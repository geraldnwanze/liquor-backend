<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Services\Catalog\ReviewPhotos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Customer reviews. Only people who bought the product can write one, and it is shown only after staff approve it. */
class ReviewController extends Controller
{
    public function __construct(private ReviewPhotos $photos) {}

    public function index(string $slug): JsonResponse
    {
        $product = $this->product($slug);
        $approved = $product->reviews()->approved();
        $distribution = (clone $approved)->selectRaw('rating, count(*) as total')->groupBy('rating')->pluck('total', 'rating');
        $count = (int) $distribution->sum();

        $page = (clone $approved)->with(['user:id,name', 'images'])->latest('id')->paginate(10);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Review $r) => [
                'id' => $r->id,
                'rating' => $r->rating,
                'title' => $r->title,
                'body' => $r->body,
                'author' => $this->displayName($r->user->name),
                'photos' => $r->images->map(fn ($i) => $i->url)->values(),
                'reply' => $r->reply ? ['text' => $r->reply, 'at' => $r->replied_at?->toIso8601String()] : null,
                'verified_purchase' => $r->order_id !== null,
                'created_at' => $r->created_at->toIso8601String(),
            ])->values(),
            'summary' => [
                'count' => $count,
                'average' => $count ? round($distribution->map(fn ($n, $stars) => $n * $stars)->sum() / $count, 1) : null,
                'distribution' => collect(range(5, 1))->mapWithKeys(fn (int $s) => [$s => (int) ($distribution[$s] ?? 0)]),
            ],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** What the signed-in customer may do on this product: write a review, or see the one they already wrote. */
    public function mine(Request $request, string $slug): JsonResponse
    {
        $product = $this->product($slug);
        $review = $product->reviews()->where('user_id', $request->user()->id)->first();

        return response()->json(['data' => [
            'can_review' => $review === null && $this->purchase($request, $product) !== null,
            'review' => $review ? ['rating' => $review->rating, 'title' => $review->title, 'body' => $review->body, 'status' => $review->status] : null,
        ]]);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $product = $this->product($slug);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'min:10', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:'.ReviewPhotos::MAX],
            'photos.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,webp', 'dimensions:min_width=200,min_height=200,max_width=4500,max_height=4500'],
        ], [
            'photos.max' => 'You can add up to '.ReviewPhotos::MAX.' photos.',
            'photos.*.max' => 'Each photo must be 5 MB or smaller.',
            'photos.*.mimes' => 'Photos must be JPEG, PNG or WebP.',
            'photos.*.dimensions' => 'Each photo must be between 200 and 4500 pixels on each side.',
        ]);

        if ($product->reviews()->where('user_id', $request->user()->id)->exists()) {
            return response()->json(['message' => 'You have already reviewed this product.', 'code' => 'already_reviewed'], 409);
        }
        $order = $this->purchase($request, $product);
        if (! $order) {
            return response()->json(['message' => 'Only customers who have bought this product can review it.', 'code' => 'review_requires_purchase'], 403);
        }

        $fields = collect($data)->except('photos')->all() + ['order_id' => $order->id, 'status' => Review::PENDING, 'moderated_by' => null, 'moderated_at' => null, 'reply' => null, 'replied_at' => null, 'replied_by' => null];

        // One review per customer per product, even across a delete: a deleted review is reused (with a clean slate)
        // rather than duplicated. Its old photos stay deleted.
        $review = $product->reviews()->onlyTrashed()->where('user_id', $request->user()->id)->first();
        if ($review) {
            $review->restoreQuietly();
            $review->update($fields);
        } else {
            $review = Review::create($fields + ['product_id' => $product->id, 'user_id' => $request->user()->id]);
        }
        $this->photos->attach($review, $request->file('photos', []));

        return response()->json(['message' => 'Thank you! Your review will appear once our team has approved it.'], 201);
    }

    /** Customers can take their own review down (photos included). They may then write a new one. */
    public function destroyMine(Request $request, string $slug): JsonResponse
    {
        $review = $this->product($slug)->reviews()->where('user_id', $request->user()->id)->first();
        if ($review) {
            $review->delete();
        }

        return response()->json(null, 204);
    }

    private function product(string $slug): Product
    {
        return Product::query()->published()->where('slug', $slug)->firstOrFail();
    }

    /** The customer's most recent paid order containing this product, if any. */
    private function purchase(Request $request, Product $product): ?Order
    {
        return Order::query()
            ->where('user_id', $request->user()->id)
            ->where('status', OrderStatus::Paid->value)
            ->whereHas('items.variant', fn ($v) => $v->where('product_id', $product->id))
            ->latest('id')
            ->first();
    }

    /** "Ada Obi" becomes "Ada O.": enough to feel real, not enough to identify someone. */
    private function displayName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = Str::limit($parts[0] ?? 'Customer', 20, '');

        return count($parts) > 1 ? $first.' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : $first;
    }
}
