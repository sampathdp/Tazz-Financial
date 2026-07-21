<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return Cache::remember('dashboard:summary', 60, function () {
            return [
                'kpis' => [
                    'sales_month'  => 0,
                    'gross_profit' => 0,
                    'receivables'  => 0,
                    'stock_value'  => 0,
                    'total_skus'   => 0,
                    'out_of_stock' => 0,
                    'low_stock'    => 0,
                ],
                'stock_by_category' => [],
                'sales_trend' => [],
                'low_stock' => [],
                'top_items' => [],
                'reps' => [],
                'pending_returns' => 0,
                'pending_approvals' => [
                    'purchase_orders' => 0,
                    'grns'            => 0,
                    'sales_returns'   => 0,
                ],
                'open_quotations' => 0,
                'recent_invoices' => [],
                'top_customers' => [],
            ];
        });
    }
}
