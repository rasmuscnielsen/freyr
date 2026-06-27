<?php

namespace App\Http\Controllers;

use App\Finance\EnableBanking\EnableBankingConnectionManager;
use App\Http\Requests\StartBankConnectionRequest;
use App\Jobs\SyncBankConnectionAccountJob;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}
