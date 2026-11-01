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
    // API group: what the screens open with (me, catalog) and the figures (overview, home, domain dashboard, bottlenecks, reports).
    // Thin: what each role may see is decided in the services and in AccessScope.

    public function __construct(
        private readonly CatalogService $catalogService,
        private readonly MetricsService $metricsService,
        private readonly DashboardService $dashboardService,
        private readonly ReportService $reportService,
    ) {
    }

    /** GET /api/bookings-approver-management/me - the user, roles, permissions, capabilities and the booking types they manage. @return JsonResponse */
    public function me(): JsonResponse
    {
        return response()->json(['data' => $this->catalogService->me($this->accessScope())], Response::HTTP_OK);
    }

    /** GET /api/bookings-approver-management/catalog - statuses, stages, roles, booking types, units, departments and action types for the dropdowns. @return JsonResponse */
    public function catalog(): JsonResponse
    {
        return response()->json(['data' => $this->catalogService->catalog($this->accessScope())], Response::HTTP_OK);
    }

    /** GET /api/bookings-approver-management/overview - the user's own dashboard: open, awaiting me, overdue, stale, by stage, trend. @return JsonResponse */
    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->metricsService->overview($this->accessScope())], Response::HTTP_OK);
    }

    /** GET /api/bookings-approver-management/bottlenecks - where the bookings wait (?days, ?types, ?unit). @param Request $request @return JsonResponse */
    public function bottlenecks(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->metricsService->bottlenecks($this->accessScope(), $request->only(['days', 'types', 'unit']))], Response::HTTP_OK);
    }

    /** GET /api/bookings-approver-management/domain-dashboard - the dashboard of booking types (?types=1,2 &days=30); approver, admin, super admin. @param Request $request @return JsonResponse */
    public function domainDashboard(Request $request): JsonResponse
    {
        $types = array_filter(explode(',', (string) $request->query('types')));

        return response()->json(['data' => $this->dashboardService->domain($this->accessScope(), $types, (int) $request->query('days', 30))], Response::HTTP_OK);
    }

    /** GET /api/bookings-admin-management/home - a tile per booking type with what is open by stage; admin and super admin. @return JsonResponse */
    public function home(): JsonResponse
    {
        return response()->json(['data' => $this->dashboardService->home($this->accessScope())], Response::HTTP_OK);
    }

    /** GET /api/bookings-admin-management/reports - report rows for the filters sent; admin and super admin. @param Request $request @return JsonResponse */
    public function reports(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reportService->rows($this->accessScope(), $request->query())], Response::HTTP_OK);
    }
}
