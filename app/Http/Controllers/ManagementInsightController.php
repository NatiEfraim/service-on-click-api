<?php

namespace App\Http\Controllers;

use App\Services\CatalogService;
use App\Services\DashboardService;
use App\Services\MetricsService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ManagementInsightController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalogService,
        private readonly MetricsService $metricsService,
        private readonly DashboardService $dashboardService,
        private readonly ReportService $reportService,
    ) {
    }

    /** The user, roles, permissions, capabilities and the booking types they manage. */
    public function me(): JsonResponse
    {
        return response()->json(['data' => $this->catalogService->me($this->accessScope())], Response::HTTP_OK);
    }

    /** Statuses, stages, roles, booking types, units, departments and action types for the dropdowns. */
    public function catalog(): JsonResponse
    {
        return response()->json(['data' => $this->catalogService->catalog($this->accessScope())], Response::HTTP_OK);
    }

    /** What waits for the user (awaiting_me.count) and the open bookings per type (by_type) and per type and stage (by_type_stage). */
    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->metricsService->overview($this->accessScope())], Response::HTTP_OK);
    }

    /** Where the bookings wait (?days, ?types, ?unit). */
    public function bottlenecks(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->metricsService->bottlenecks($this->accessScope(), $request->only(['days', 'types', 'unit']))], Response::HTTP_OK);
    }

    /** The dashboard of booking types (?types=1,2 &days=30); approver, admin, super admin. */
    public function domainDashboard(Request $request): JsonResponse
    {
        $types = array_filter(explode(',', (string) $request->query('types')));

        return response()->json(['data' => $this->dashboardService->domain($this->accessScope(), $types, (int) $request->query('days', 30))], Response::HTTP_OK);
    }

    /** A tile per booking type with what is open by stage; admin and super admin. */
    public function home(): JsonResponse
    {
        return response()->json(['data' => $this->dashboardService->home($this->accessScope())], Response::HTTP_OK);
    }

    /** Report rows for the filters sent; admin and super admin. */
    public function reports(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reportService->rows($this->accessScope(), $request->query())], Response::HTTP_OK);
    }
}
