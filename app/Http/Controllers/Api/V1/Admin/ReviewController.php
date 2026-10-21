<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\Administration\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected']])['status'] ?? Review::PENDING;

        $page = Review::query()->where('status', $status)->with(['product:id,name,slug', 'user:id,name,email', 'images'])->latest('id')->paginate(25);
        $page->getCollection()->transform(fn (Review $r) => [
            'id' => $r->id,
            'rating' => $r->rating,
            'title' => $r->title,
            'body' => $r->body,
            'status' => $r->status,
            'photos' => $r->images->map(fn ($i) => $i->url)->values(),
            'reply' => $r->reply,
            'verified_purchase' => $r->order_id !== null,
            'product' => $r->product?->only(['name', 'slug']),
            'customer' => $r->user?->only(['name', 'email']),
            'created_at' => $r->created_at->toIso8601String(),
        ]);

        return JsonResource::collection($page);
    }

    public function approve(Review $review): JsonResponse
    {
        return $this->moderate($review, Review::APPROVED, 'review.approved');
    }

    public function reject(Review $review): JsonResponse
    {
        return $this->moderate($review, Review::REJECTED, 'review.rejected');
    }

    public function destroy(Review $review): JsonResponse
    {
        $this->audit->record('review.deleted', $review, ['product_id' => $review->product_id]);
        $review->delete();

        return response()->json(null, 204);
    }

    /** A public answer under the review. Posting again replaces it. */
    public function reply(Request $request, Review $review): JsonResponse
    {
        $text = $request->validate(['reply' => ['required', 'string', 'min:2', 'max:1000']])['reply'];
        $review->update(['reply' => trim($text), 'replied_at' => now(), 'replied_by' => auth()->id()]);
        $this->audit->record('review.replied', $review, ['product_id' => $review->product_id]);

        return response()->json(['data' => ['id' => $review->id, 'reply' => $review->reply]]);
    }

    public function removeReply(Review $review): JsonResponse
    {
        $review->update(['reply' => null, 'replied_at' => null, 'replied_by' => null]);
        $this->audit->record('review.reply_removed', $review, ['product_id' => $review->product_id]);

        return response()->json(null, 204);
    }

    private function moderate(Review $review, string $status, string $action): JsonResponse
    {
        $review->update(['status' => $status, 'moderated_by' => auth()->id(), 'moderated_at' => now()]);
        $this->audit->record($action, $review, ['product_id' => $review->product_id]);

        return response()->json(['data' => ['id' => $review->id, 'status' => $review->status]]);
    }
}
