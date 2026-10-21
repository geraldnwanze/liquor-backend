<?php

namespace App\Console\Commands;

use App\Enums\PaymentState;
use App\Models\Payment;
use App\Services\Payments\PaymentVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:reconcile')]
#[Description('Re-check pending payments with the gateway (safety net for missed webhooks and closed browser tabs)')]
class ReconcilePayments extends Command
{
    public function handle(PaymentVerifier $verifier): int
    {
        $minAge = (int) config('payments.reconcile.min_age_minutes');
        $giveUp = now()->subHours((int) config('payments.reconcile.give_up_after_hours'));
        $checked = $paid = $abandoned = 0;

        Payment::query()
            ->whereIn('state', [PaymentState::Pending, PaymentState::Failed, PaymentState::Abandoned])
            ->where('created_at', '<=', now()->subMinutes($minAge))
            ->where('created_at', '>', now()->subDays(3)) // late successes (e.g. bank transfer) can still land
            ->chunkById(50, function ($payments) use ($verifier, $giveUp, &$checked, &$paid, &$abandoned): void {
                foreach ($payments as $payment) {
                    $after = $verifier->verify($payment, 'reconcile');
                    $checked++;

                    if ($after->state === PaymentState::Succeeded) {
                        $paid++;
                    }
                    // Still pending long after the customer left: stop treating it as live.
                    if ($after->state === PaymentState::Pending && $after->created_at < $giveUp) {
                        $after->forceFill(['state' => PaymentState::Abandoned])->save();
                        $abandoned++;
                    }
                }
            });

        $this->info("Checked {$checked} payment(s): {$paid} newly paid, {$abandoned} abandoned.");

        return self::SUCCESS;
    }
}
