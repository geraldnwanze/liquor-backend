<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;

class NewsletterController extends Controller
{
    /** Confirmed, still-subscribed addresses (newest first) with list totals. Sending is done in your email tool. */
    public function index(): JsonResponse
    {
        $active = NewsletterSubscriber::whereNotNull('confirmed_at')->whereNull('unsubscribed_at');

        return response()->json([
            'data' => (clone $active)->latest('confirmed_at')->limit(5000)->get(['email', 'confirmed_at', 'consented_at', 'consent_text']),
            'summary' => [
                'active' => (clone $active)->count(),
                'awaiting_confirmation' => NewsletterSubscriber::whereNull('confirmed_at')->whereNull('unsubscribed_at')->count(),
                'unsubscribed' => NewsletterSubscriber::whereNotNull('unsubscribed_at')->count(),
            ],
        ]);
    }
}
