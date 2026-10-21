<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\CommerceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use App\Services\Administration\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Who can sign in to the admin, and as what. Only a super administrator can reach this (no other role holds
 * `staff.manage`), because anyone who can assign roles can give themselves everything.
 *
 * Guard rails: you cannot change your own roles or deactivate yourself, and the last active super administrator
 * can never be demoted or deactivated, so the shop cannot be locked out of its own admin.
 */
class StaffController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $staff = User::query()->whereHas('roles')->with('roles:id,slug,name')->orderBy('name')->get();

        return response()->json(['data' => $staff->map(fn (User $u) => $this->present($u))->values()]);
    }

    public function roles(): JsonResponse
    {
        $roles = Role::query()->with('permissions:id,slug')->orderBy('name')->get()->map(fn (Role $r) => [
            'slug' => $r->slug,
            'name' => $r->name,
            'permissions' => $r->slug === 'super-admin' ? ['*'] : $r->permissions->pluck('slug')->sort()->values(),
        ]);

        return response()->json(['data' => $roles]);
    }

    public function store(StaffRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data): User {
            // Nobody knows this password: the person sets their own from the invitation link.
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::random(64)]);
            $user->forceFill(['is_active' => true])->save();
            $user->roles()->sync(Role::whereIn('slug', $data['roles'])->pluck('id'));

            return $user;
        });
        $this->invite($user, $request->user());
        $this->audit->record('staff.invited', $user, ['email' => $user->email, 'roles' => $data['roles']]);

        return response()->json(['data' => $this->present($user->refresh()->load('roles:id,slug,name'))], 201);
    }

    public function update(StaffRequest $request, string $staff): JsonResponse
    {
        $user = $this->find($staff);
        $data = $request->validated();

        if (isset($data['roles'])) {
            $this->refuseSelf($request, $user, 'change your own roles');
            $before = $user->roles->pluck('slug')->sort()->values()->all();
            $after = collect($data['roles'])->sort()->values()->all();
            if ($before !== $after) {
                if (in_array('super-admin', $before, true) && ! in_array('super-admin', $after, true)) {
                    $this->refuseLastSuperAdmin($user);
                }
                $user->roles()->sync(Role::whereIn('slug', $data['roles'])->pluck('id'));
                $this->audit->record('staff.roles_changed', $user, ['from' => $before, 'to' => $after]);
            }
        }
        if (isset($data['name']) && $data['name'] !== $user->name) {
            $user->update(['name' => $data['name']]);
            $this->audit->record('staff.renamed', $user);
        }

        return response()->json(['data' => $this->present($user->refresh()->load('roles:id,slug,name'))]);
    }

    public function deactivate(Request $request, string $staff): JsonResponse
    {
        $user = $this->find($staff);
        $this->refuseSelf($request, $user, 'deactivate yourself');
        if ($user->hasRole('super-admin')) {
            $this->refuseLastSuperAdmin($user);
        }

        $user->forceFill(['is_active' => false])->save();
        $user->tokens()->delete(); // signed out everywhere, immediately
        $this->audit->record('staff.deactivated', $user);

        return response()->json(['data' => $this->present($user->load('roles:id,slug,name'))]);
    }

    public function reactivate(string $staff): JsonResponse
    {
        $user = $this->find($staff);
        $user->forceFill(['is_active' => true])->save();
        $this->audit->record('staff.reactivated', $user);

        return response()->json(['data' => $this->present($user->load('roles:id,slug,name'))]);
    }

    public function resendInvite(Request $request, string $staff): JsonResponse
    {
        $user = $this->find($staff);
        if (! $user->is_active) {
            throw new CommerceException('staff_inactive', 'Reactivate this person before sending an invitation.', 409);
        }
        $this->invite($user, $request->user());
        $this->audit->record('staff.invite_resent', $user);

        return response()->json(['message' => 'Invitation sent.']);
    }

    private function invite(User $user, User $by): void
    {
        $user->loadMissing('roles:id,slug,name');
        $user->notify(new StaffInvitationNotification(Password::broker()->createToken($user), $user->roles->pluck('name')->all(), $by->name));
    }

    private function find(string $id): User
    {
        return User::query()->whereHas('roles')->with('roles:id,slug,name')->findOrFail($id);
    }

    private function refuseSelf(Request $request, User $user, string $what): void
    {
        if ($request->user()->is($user)) {
            throw new CommerceException('cannot_modify_self', "You can't {$what}. Ask another super administrator.", 409);
        }
    }

    /** Called when $user is a super administrator about to lose that role or access. */
    private function refuseLastSuperAdmin(User $user): void
    {
        $others = User::query()->where('is_active', true)->whereKeyNot($user->id)->whereHas('roles', fn ($q) => $q->where('slug', 'super-admin'))->count();
        if ($others === 0) {
            throw new CommerceException('last_super_admin', 'There must always be at least one active super administrator.', 409);
        }
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'roles' => $user->roles->map(fn (Role $r) => ['slug' => $r->slug, 'name' => $r->name])->values(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
