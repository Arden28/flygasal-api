<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Flights\Booking;
use App\Models\Flights\Transaction;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/summary?range=7d|30d|6m|12m
     * Returns:
     *  - totals: users, bookings, cancelled, unpaid, revenue
     *  - trends: arrays for small sparklines (same length as labels)
     *  - labels: x-axis labels matching trend points
     */
    public function summary(Request $request)
    {
        $range = $this->normalizeRange($request->query('range', '30d'));

        // Light cache; bust whenever you prefer (e.g., after write ops)
        $cacheKey = "dashboard.summary.{$range}";
        return Cache::remember($cacheKey, now()->addSeconds(60), function () use ($range) {
            [$from, $to, $period] = $this->computeWindow($range);

            // Zero-filled buckets
            [$labels, $keys] = $this->makeBuckets($from, $to, $period);
            $pointCount = count($keys);

            // --- Totals ---
            // Users (all-time)
            $totalUsers = User::count();

            // Bookings confirmed/paid in window
            $bookingStatusesConfirm = ['confirmed', 'completed', 'paid', 'issued'];
            $bookingsInRange = Booking::query()
                ->whereBetween('created_at', [$from, $to])
                ->whereIn('status', $bookingStatusesConfirm)
                ->count();

            // Cancelled in window
            $cancelledInRange = Booking::query()
                ->whereBetween('created_at', [$from, $to])
                ->where('status', 'cancelled')
                ->count();

            // Unpaid (current outstanding, all-time)
            $unpaidOpen = Booking::query()
                ->where('status', 'unpaid')
                ->count();

            // Revenue from transactions (completed booking payments) in window
            $revenueInRange = (float) Transaction::query()
                ->where('type', 'booking')
                ->where('status', 'completed')
                ->whereBetween('created_at', [$from, $to])
                ->sum('amount');

            // --- Trends (zero-filled, then fill from rows) ---
            $trendUsers       = array_fill(0, $pointCount, 0);
            $trendBookings    = array_fill(0, $pointCount, 0);
            $trendCancelled   = array_fill(0, $pointCount, 0);
            $trendUnpaid      = array_fill(0, $pointCount, 0);
            $trendRevenue     = array_fill(0, $pointCount, 0);

            // Users created in window
            User::query()
                ->whereBetween('created_at', [$from, $to])
                ->get(['id', 'created_at'])
                ->each(function ($u) use (&$trendUsers, $keys, $period) {
                    $k = $this->bucketKey($u->created_at, $period);
                    if (($idx = array_search($k, $keys, true)) !== false) {
                        $trendUsers[$idx] += 1;
                    }
                });

            // Bookings by status in window
            Booking::query()
                ->whereBetween('created_at', [$from, $to])
                ->get(['id', 'created_at', 'status'])
                ->each(function ($b) use (&$trendBookings, &$trendCancelled, &$trendUnpaid, $keys, $period, $bookingStatusesConfirm) {
                    $k = $this->bucketKey($b->created_at, $period);
                    if (($idx = array_search($k, $keys, true)) === false) {
                        return;
                    }
                    if (in_array($b->status, $bookingStatusesConfirm, true)) {
                        $trendBookings[$idx] += 1;
                    } elseif ($b->status === 'cancelled') {
                        $trendCancelled[$idx] += 1;
                    } elseif ($b->status === 'unpaid') {
                        // Count new unpaid created in the bucket (sparkline signal)
                        $trendUnpaid[$idx] += 1;
                    }
                });

            // Revenue by bucket
            Transaction::query()
                ->where('type', 'booking')
                ->where('status', 'completed')
                ->whereBetween('created_at', [$from, $to])
                ->get(['amount', 'created_at'])
                ->each(function ($t) use (&$trendRevenue, $keys, $period) {
                    $k = $this->bucketKey($t->created_at, $period);
                    if (($idx = array_search($k, $keys, true)) !== false) {
                        $trendRevenue[$idx] += (float) $t->amount;
                    }
                });

            return response()->json([
                'status' => true,
                'data' => [
                    'range'   => $range,
                    'period'  => $period,         // "day" | "month"
                    'labels'  => $labels,         // x-axis labels matching trends
                    'currency'=> 'USD',           // adjust if multi-currency
                    'totals'  => [
                        'users'     => $totalUsers,
                        'bookings'  => $bookingsInRange,
                        'cancelled' => $cancelledInRange,
                        'unpaid'    => $unpaidOpen,
                        'revenue'   => $revenueInRange,
                    ],
                    'trends'  => [
                        'users'     => $trendUsers,
                        'bookings'  => $trendBookings,
                        'cancelled' => $trendCancelled,
                        'unpaid'    => $trendUnpaid,
                        'revenue'   => $trendRevenue,
                    ],
                ],
            ]);
        });
    }

    /**
     * GET /api/dashboard/sales?range=7d|30d|6m|12m
     * Returns labels + a single "sales" (revenue) dataset for the chart.
     */
    public function sales(Request $request)
    {
        $range = $this->normalizeRange($request->query('range', '30d'));

        $cacheKey = "dashboard.sales.{$range}";
        return Cache::remember($cacheKey, now()->addSeconds(60), function () use ($range) {
            [$from, $to, $period] = $this->computeWindow($range);
            [$labels, $keys] = $this->makeBuckets($from, $to, $period);
            $series = array_fill(0, count($keys), 0.0);

            Transaction::query()
                ->where('type', 'booking')
                ->where('status', 'completed')
                ->whereBetween('created_at', [$from, $to])
                ->get(['amount', 'created_at'])
                ->each(function ($t) use (&$series, $keys, $period) {
                    $k = $this->bucketKey($t->created_at, $period);
                    if (($idx = array_search($k, $keys, true)) !== false) {
                        $series[$idx] += (float) $t->amount;
                    }
                });

            return response()->json([
                'status' => true,
                'data' => [
                    'range'    => $range,
                    'period'   => $period,
                    'currency' => 'USD',
                    'labels'   => $labels,
                    'datasets' => [
                        [
                            'label' => 'Sales',
                            'data'  => $series,
                        ],
                    ],
                ],
            ]);
        });
    }

    /* -------------------- Helpers -------------------- */

    private function normalizeRange(string $range): string
    {
        $allowed = ['7d', '30d', '6m', '12m'];
        return in_array($range, $allowed, true) ? $range : '30d';
    }

    /**
     * @return array{0:Carbon,1:Carbon,2:string} [$from, $to, $period]
     */
    private function computeWindow(string $range): array
    {
        $now = Carbon::now();
        switch ($range) {
            case '7d':
                return [$now->copy()->startOfDay()->subDays(6), $now->copy()->endOfDay(), 'day'];
            case '30d':
                return [$now->copy()->startOfDay()->subDays(29), $now->copy()->endOfDay(), 'day'];
            case '6m':
                return [$now->copy()->startOfMonth()->subMonths(5), $now->copy()->endOfMonth(), 'month'];
            case '12m':
                return [$now->copy()->startOfMonth()->subMonths(11), $now->copy()->endOfMonth(), 'month'];
            default:
                return [$now->copy()->startOfDay()->subDays(29), $now->copy()->endOfDay(), 'day'];
        }
    }

    /**
     * Build zero-filled buckets and pretty labels.
     *
     * @return array{0:array<int,string>,1:array<int,string>} [$labels, $keys]
     *  - $keys are the canonical bucket keys we use to match data (Y-m-d or Y-m)
     */
    private function makeBuckets(Carbon $from, Carbon $to, string $period): array
    {
        if ($period === 'month') {
            $from = $from->copy()->startOfMonth();
            $to   = $to->copy()->startOfMonth();
            $step = '1 month';
            $formatKey = 'Y-m';
            $formatLabel = 'M Y';
        } else { // day
            $from = $from->copy()->startOfDay();
            $to   = $to->copy()->startOfDay();
            $step = '1 day';
            $formatKey = 'Y-m-d';
            $formatLabel = 'M j';
        }

        $periodIter = CarbonPeriod::create($from, $step, $to);
        $keys = [];
        $labels = [];

        foreach ($periodIter as $d) {
            $keys[] = $d->format($formatKey);
            $labels[] = $d->translatedFormat($formatLabel);
        }

        return [$labels, $keys];
    }

    /**
     * Get the canonical bucket key for a timestamp.
     */
    private function bucketKey($timestamp, string $period): string
    {
        $dt = $timestamp instanceof Carbon ? $timestamp : Carbon::parse($timestamp);
        return $period === 'month'
            ? $dt->copy()->startOfMonth()->format('Y-m')
            : $dt->copy()->startOfDay()->format('Y-m-d');
    }
}
