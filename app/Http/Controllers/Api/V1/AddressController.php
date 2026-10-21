<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CommerceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    private const MAX_PER_USER = 10;

    public function index(Request $request): AnonymousResourceCollection
    {
        return AddressResource::collection($request->user()->addresses()->orderByDesc('is_default')->orderBy('id')->get());
    }

    public function store(AddressRequest $request): JsonResponse
    {
        $user = $request->user();

        $address = DB::transaction(function () use ($request, $user): Address {
            if ($user->addresses()->lockForUpdate()->count() >= self::MAX_PER_USER) {
                throw new CommerceException('address_limit', 'You can save up to '.self::MAX_PER_USER.' addresses. Remove one to add another.');
            }

            // The first address is always the default; otherwise only when asked.
            $makeDefault = $request->boolean('is_default') || ! $user->addresses()->exists();
            if ($makeDefault) {
                $user->addresses()->update(['is_default' => false]);
            }

            return $user->addresses()->create([...$request->validated(), 'is_default' => $makeDefault])->refresh();
        });

        return (new AddressResource($address))->response()->setStatusCode(201);
    }

    public function update(AddressRequest $request, string $address): AddressResource
    {
        // Looked up through the signed-in customer: someone else's address id is a plain 404.
        $model = $request->user()->addresses()->findOrFail($address);

        DB::transaction(function () use ($request, $model): void {
            if ($request->boolean('is_default')) {
                $model->user->addresses()->whereKeyNot($model->id)->update(['is_default' => false]);
            }
            // A default address cannot be un-defaulted directly: choose another one as default instead.
            $data = $request->validated();
            if ($model->is_default) {
                $data['is_default'] = true;
            }
            $model->update($data);
        });

        return new AddressResource($model->refresh());
    }

    public function destroy(Request $request, string $address): JsonResponse
    {
        $model = $request->user()->addresses()->findOrFail($address);

        DB::transaction(function () use ($model): void {
            $wasDefault = $model->is_default;
            $user = $model->user;
            $model->delete();

            if ($wasDefault) {
                $user->addresses()->latest('id')->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(null, 204);
    }
}
