<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\CustomerNote;
use App\Models\User;
use App\Services\Administration\AuditLogger;
use App\Support\Money;
use App\Support\NigerianPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer accounts for support staff. Staff accounts are never listed or opened here (they are managed as staff),
 * and nothing about passwords or tokens is ever returned.
 */
class CustomerController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,suspended'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->customers()
            ->withCount(['orders as paid_orders_count' => fn ($q) => $q->where('status', OrderStatus::Paid)])
            ->withSum(['orders as spent_kobo' => fn ($q) => $q->where('status', OrderStatus::Paid)], 'total_kobo')
            ->when($f['status'] ?? null, fn (Builder $q, string $status) => $q->where('is_active', $status === 'active'))
            ->when($f['q'] ?? null, function (Builder $q, string $term) {
                $like = '%'.addcslashes(trim($term), '%_\\').'%';
                $phone = NigerianPhone::normalize($term);
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)
                    ->when($phone, fn ($x) => $x->orWhere('phone', $phone)));
            })
            ->latest('id')->paginate($f['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'is_active' => (bool) $u->is_active,
                'suspended_at' => $u->suspended_at?->toIso8601String(),
                'erased' => $u->anonymized_at !== null,
                'paid_orders' => (int) $u->paid_orders_count,
                'spent' => Money::toArray((int) $u->spent_kobo),
                'joined_at' => $u->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(string $customer): JsonResponse
    {
        $user = $this->find($customer);
        $paid = $user->orders()->where('status', OrderStatus::Paid);

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => (bool) $user->is_active,
            'suspended_at' => $user->suspended_at?->toIso8601String(),
            'suspension_reason' => $user->suspension_reason,
            'erased' => $user->anonymized_at !== null,
            'date_of_birth' => $user->date_of_birth?->toDateString(),
            'joined_at' => $user->created_at?->toIso8601String(),
            'stats' => [
                'orders' => $user->orders()->count(),
                'paid_orders' => (clone $paid)->count(),
                'spent' => Money::toArray((int) (clone $paid)->sum('total_kobo')),
            ],
            'addresses' => $user->addresses()->orderByDesc('is_default')->orderBy('id')->get()->map(fn ($a) => [
                'label' => $a->label, 'recipient_name' => $a->recipient_name, 'phone' => $a->phone, 'state' => $a->state,
                'city' => $a->city, 'address_line' => $a->address_line, 'instructions' => $a->instructions, 'is_default' => $a->is_default,
            ])->values(),
            'orders' => $user->orders()->latest('id')->limit(20)->get()->map(fn ($o) => [
                'reference' => $o->reference,
                'status' => $o->status->value,
                'payment_status' => $o->payment_status->value,
                'fulfilment_status' => $o->fulfilment_status->value,
                'total' => Money::toArray($o->total_kobo),
                'created_at' => $o->created_at->toIso8601String(),
            ])->values(),
            'notes' => $this->notes($user),
        ]]);
    }

    /**
     * Blocks the account: the customer is signed out everywhere at once and cannot sign in until reactivated. Orders,
     * addresses and history are untouched. The reason is kept on the account, in the audit log and as a support note.
     */
    public function suspend(Request $request, string $customer): JsonResponse
    {
        $user = $this->find($customer);
        abort_if($user->anonymized_at, 409, 'This customer has been erased.');
        $request->merge(['reason' => trim(strip_tags((string) $request->input('reason')))]);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?: null;

        if ($user->is_active) {
            $user->forceFill(['is_active' => false, 'suspended_at' => now(), 'suspension_reason' => $reason])->save();
            $user->tokens()->delete();
            $user->supportNotes()->create(['author_id' => $request->user()->id, 'body' => 'Account suspended'.($reason ? ": {$reason}" : '.')]);
            $this->audit->record('customer.suspended', $user, ['reason' => $reason]);
        }

        return response()->json(['data' => $this->state($user)]);
    }

    public function reactivate(Request $request, string $customer): JsonResponse
    {
        $user = $this->find($customer);
        abort_if($user->anonymized_at, 409, 'This customer has been erased.');

        if (! $user->is_active) {
            $user->forceFill(['is_active' => true, 'suspended_at' => null, 'suspension_reason' => null])->save();
            $user->supportNotes()->create(['author_id' => $request->user()->id, 'body' => 'Account reactivated.']);
            $this->audit->record('customer.reactivated', $user);
        }

        return response()->json(['data' => $this->state($user)]);
    }

    /** @return array<string, mixed> */
    private function state(User $user): array
    {
        return ['id' => $user->id, 'is_active' => (bool) $user->is_active, 'suspended_at' => $user->suspended_at?->toIso8601String(), 'suspension_reason' => $user->suspension_reason, 'notes' => $this->notes($user)];
    }

    public function addNote(Request $request, string $customer): JsonResponse
    {
        $user = $this->find($customer);
        // Clean first, then validate, so a note that is only markup is rejected rather than saved empty.
        $request->merge(['body' => trim(strip_tags((string) $request->input('body')))]);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:1000']]);

        $note = $user->supportNotes()->create(['author_id' => $request->user()->id, 'body' => $data['body']]);
        $this->audit->record('customer.note_added', $user, ['note_id' => $note->id]);

        return response()->json(['data' => $this->notes($user)], 201);
    }

    public function deleteNote(string $customer, string $note): JsonResponse
    {
        $user = $this->find($customer);
        // Looked up through this customer, so a note id belonging to someone else is simply a 404.
        $record = $user->supportNotes()->findOrFail($note);
        $this->audit->record('customer.note_deleted', $user, ['note_id' => $record->id]);
        $record->delete();

        return response()->json(['data' => $this->notes($user)]);
    }

    /** @return Builder<User> */
    private function customers(): Builder
    {
        return User::query()->whereDoesntHave('roles');
    }

    private function find(string $id): User
    {
        return $this->customers()->findOrFail($id);
    }

    /** @return list<array<string, mixed>> */
    private function notes(User $user): array
    {
        return $user->supportNotes()->with('author:id,name')->latest('id')->get()->map(fn (CustomerNote $n) => [
            'id' => $n->id, 'body' => $n->body, 'by' => $n->author?->name, 'at' => $n->created_at->toIso8601String(),
        ])->values()->all();
    }
}
