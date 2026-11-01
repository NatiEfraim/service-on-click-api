<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatusCodeEnum;
use App\Exceptions\DomainError;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ManagementAuditController extends Controller
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    /** One page of the audit log (?serial ?actor ?action ?source ?type ?result ?from ?to ?page). */
    public function index(Request $request): JsonResponse
    {
        $this->need();

        return response()->json($this->auditService->search($request->query()), Response::HTTP_OK);
    }

    /** Check the hash chain of the whole log. */
    public function verify(): JsonResponse
    {
        $this->need();

        return response()->json(['data' => $this->auditService->verify()], Response::HTTP_OK);
    }

    /** Refused and audited: the history cannot be edited or removed. */
    public function immutable(string $id, Request $request): JsonResponse
    {
        $this->auditService->denied('audit.modify', 'audit_log', $id, 'append_only', $request->user());

        return response()->json(['message' => HttpStatusCodeEnum::DENIED_MESSAGE], Response::HTTP_METHOD_NOT_ALLOWED);
    }

    /** @throws DomainError 403 */
    private function need(): void
    {
        if (!$this->accessScope()->can('audit.view')) {
            throw new DomainError('אין לך הרשאה למידע זה', 403);
        }
    }
}
