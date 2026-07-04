<?php

namespace App\Http\Controllers;

use App\Finance\EnableBanking\EnableBankingConnectionManager;
use App\Http\Requests\StartBankConnectionRequest;
use App\Jobs\SyncBankConnectionAccountJob;
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
     * Remove a bank connection while keeping the local accounts and their postings.
     *
     * The remote accounts are detached (their local bank_account link is nulled and the
     * remote-account rows are deleted) so the user keeps synced postings and balances as
     * manual accounts. Sync run history cascades away with the connection at the database
     * level (bank_sync_runs.bank_connection_id is ON DELETE CASCADE).
     */
    public function destroy(Request $request, BankConnection $bankConnection): RedirectResponse
    {
        abort_unless($request->user()?->is($bankConnection->user), 403);

        DB::transaction(function () use ($bankConnection): void {
            $bankConnection->accounts()->update(['bank_account_id' => null]);
            $bankConnection->accounts()->delete();
            $bankConnection->delete();
        });

        return back()->with('status', 'Bank connection removed. Your accounts and postings were kept.');
    }
}
