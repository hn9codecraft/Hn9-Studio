<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\UserServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private UserServiceInterface $users) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $perPage = (int) ($request->query('perPage', 15));

        $page = $this->users->paginate($perPage, $request->only(['status', 'role']));

        return ApiResponse::success(UserResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $user = $this->users->getByUuid($uuid);

        $this->authorize('view', $user);

        return ApiResponse::success(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, string $uuid): JsonResponse
    {
        $user = $this->users->getByUuid($uuid);

        $this->authorize('update', $user);

        $updated = $this->users->update($user, $request->validated());

        return ApiResponse::success(new UserResource($updated));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $user = $this->users->getByUuid($uuid);

        $this->authorize('delete', $user);

        $this->users->delete($user);

        return ApiResponse::noContent();
    }

    public function restore(string $uuid): JsonResponse
    {
        $user = $this->users->getByUuid($uuid);

        $this->authorize('restore', $user);

        $user = $this->users->restore($uuid);

        return ApiResponse::success(new UserResource($user));
    }
}
