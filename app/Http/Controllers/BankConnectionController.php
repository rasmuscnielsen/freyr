<?php

namespace App\Http\Controllers;

use App\Enums\SyncHistory;
use App\Finance\AccountBalanceRecalculator;
use App\Finance\BankConnectionAccountLinker;
use App\Finance\EnableBanking\EnableBankingConnectionManager;
use App\Http\Requests\MapBankConnectionAccountsRequest;
use App\Http\Requests\StartBankConnectionRequest;
use App\Jobs\SyncBankConnectionAccountJob;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class BankConnectionController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('bank-accounts.index');
    }

    public function store(StartBankConnectionRequest $request, EnableBankingConnectionManager $manager): Response
    {
        $url = $manager->startAuthorization(
            $request->user(),
            aspspName: $request->validated('aspsp_name') ?: null,
            aspspCountry: $request->validated('aspsp_country') ?: null,
        );

        return Inertia::location($url);
    }

    public function callback(Request $request, EnableBankingConnectionManager $manager): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('bank-accounts.index')
                ->with('error', $request->query('error_description', $request->query('error')));
        }

        $connection = $manager->completeAuthorization(
            $request->user(),
            (string) $request->query('state'),
            (string) $request->query('code'),
        );

        $connection->accounts()
            ->where('status', BankConnectionAccount::STATUS_LINKED)
            ->whereNotNull('bank_account_id')
            ->get()
            ->each(fn (BankConnectionAccount $account) => SyncBankConnectionAccountJob::dispatch($account));

        if ($connection->accounts()->where('status', BankConnectionAccount::STATUS_UNLINKED)->exists()) {
            return redirect()->route('bank-accounts.index', ['setup' => $connection->id]);
        }

        return redirect()->route('bank-accounts.index');
    }

    public function renew(Request $request, BankConnection $bankConnection, EnableBankingConnectionManager $manager): Response
    {
        abort_unless($request->user()?->is($bankConnection->user), 403);

        return Inertia::location($manager->startAuthorization($request->user(), $bankConnection));
    }

    /**
     * Dispatch a sync for every linked account on the connection.
     */
    public function sync(Request $request, BankConnection $bankConnection): RedirectResponse
    {
        abort_unless($request->user()?->is($bankConnection->user), 403);

        $bankConnection->accounts()
            ->where('status', BankConnectionAccount::STATUS_LINKED)
            ->whereNotNull('bank_account_id')
            ->get()
            ->each(fn (BankConnectionAccount $account) => SyncBankConnectionAccountJob::dispatch($account));

        return back();
    }

    /**
     * Map a batch of remote accounts on the connection: link to an existing
     * local account, create a new local account, or ignore the remote account.
     */
    public function mapAccounts(MapBankConnectionAccountsRequest $request, BankConnection $bankConnection, BankConnectionAccountLinker $linker): RedirectResponse
    {
        abort_unless($request->user()?->is($bankConnection->user), 403);

        DB::transaction(function () use ($request, $bankConnection, $linker): void {
            foreach ($request->validated('accounts') as $item) {
                $remote = $bankConnection->accounts()->findOrFail($item['id']);
                $history = isset($item['history']) ? SyncHistory::from($item['history']) : SyncHistory::Max;

                match ($item['mode']) {
                    'existing' => $linker->linkExisting(
                        $remote,
                        $request->user()->bankAccounts()->findOrFail($item['bank_account_id']),
                        $history,
                    ),
                    'create' => $linker->createAndLink($remote, $item['name'], $history),
                    'ignore' => $linker->ignore($remote),
                };
            }
        });

        return back()->with('status', 'Bank accounts set up.');
    }

    /**
     * Remove a bank connection while keeping the local accounts and their postings.
     *
     * The remote accounts are detached (their local bank_account link is nulled and the
     * remote-account rows are deleted) so the user keeps synced postings and balances as
     * manual accounts. Sync run history cascades away with the connection at the database
     * level (bank_sync_runs.bank_connection_id is ON DELETE CASCADE).
     */
    public function destroy(Request $request, BankConnection $bankConnection, AccountBalanceRecalculator $balanceRecalculator): RedirectResponse
    {
        abort_unless($request->user()?->is($bankConnection->user), 403);

        $detachedAccountIds = $bankConnection->accounts()->whereNotNull('bank_account_id')->pluck('bank_account_id');

        DB::transaction(function () use ($bankConnection): void {
            $bankConnection->accounts()->update(['bank_account_id' => null]);
            $bankConnection->accounts()->delete();
            $bankConnection->delete();
        });

        // Detached accounts are manual from here on: anchor their opening
        // balance while the synced running balances are still authoritative,
        // then derive their chain and current balance from postings.
        BankAccount::query()
            ->whereIn('id', $detachedAccountIds)
            ->whereDoesntHave('bankConnectionAccounts')
            ->get()
            ->each(function (BankAccount $account) use ($balanceRecalculator): void {
                $balanceRecalculator->seedOpeningBalanceFromImportedChain($account);
                $balanceRecalculator->recalculate($account);
            });

        return back()->with('status', 'Bank connection removed. Your accounts and postings were kept.');
    }
}
