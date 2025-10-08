<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\OrderAction;
use Illuminate\Support\Facades\Response;


class DashboardController extends Controller
{


    public function index(Request $request)
    {
        try {
            // Get distinct statuses for the filter dropdown
            return view('admin.dashboard');
        } catch (\Exception $e) {
            return redirect()->route('dashboard')
                ->with('error', 'Something went wrong while fetching the order.');
        }
    }


    public function view($id)
    {
        try {
            $order = Order::findOrFail($id);
            $orderData = json_decode($order->order_data, true);

            return view('admin.orders.view', compact('order', 'orderData'));
        } catch (\Exception $e) {
            \Log::error('Error while viewing order: ' . $e->getMessage(), [
                'order_id' => $id,
            ]);
            return redirect()->route('dashboard')
                ->with('error', 'Something went wrong while fetching the order.');
        }
    }

    public function export(Request $request)
    {
        try {
            $request->validate([
                'order_ids' => 'required|array|min:1',
            ]);

            $orderNumbers = $request->order_ids;
            $orders = \App\Models\Order::whereIn('order_number', $orderNumbers)->get();
         

            $flavours = [];
            foreach ($orders as $order) {
                $orderData = json_decode($order->order_data, true);
                $orderName = $orderData['name'] ?? $order->order_number;

                if (!empty($orderData['line_items'])) {
                    foreach ($orderData['line_items'] as $item) {
                        $lineQty = (int) ($item['current_quantity'] ?? 1);
                        $productId = $item['product_id'] ?? null;

                        // ✅ Case 1: Special case product_id = 7362832367650
                        if ($productId == 7362832367650) {
                            $flavourRaw = $item['variant_title'] ?? 'Unknown Variant';
                            $flavourKey = strtolower(trim($flavourRaw));
                            $finalQty = $lineQty * 12;

                            if (!isset($flavours[$flavourKey])) {
                                $flavours[$flavourKey] = [
                                    'flavour_name' => $flavourRaw,
                                    'quantity' => 0,
                                    'order_numbers' => [],
                                ];
                            }

                            $flavours[$flavourKey]['quantity'] += $finalQty;
                            $flavours[$flavourKey]['order_numbers'][$orderName] = $orderName;
                            continue;
                        }

                        // ✅ Case 2: product_id = 7362832269346 (blank properties fallback)
                        if ($productId == 7362832269346 && empty($item['properties'])) {
                            $defaultFlavours = [
                                "Orange",
                                "White Chocolate and Peanut",
                                "Biscoff",
                                "Nutella",
                                "Double Chocolate",
                                "Chocolate and Peanut",
                            ];

                            foreach ($defaultFlavours as $flavourRaw) {
                                $flavourKey = strtolower(trim($flavourRaw));
                                $finalQty = 2 * $lineQty;

                                if (!isset($flavours[$flavourKey])) {
                                    $flavours[$flavourKey] = [
                                        'flavour_name' => $flavourRaw,
                                        'quantity' => 0,
                                        'order_numbers' => [],
                                    ];
                                }

                                $flavours[$flavourKey]['quantity'] += $finalQty;
                                $flavours[$flavourKey]['order_numbers'][$orderName] = $orderName;
                            }
                            continue;
                        }

                        // ✅ Case 3: Normal logic for all other products
                        if (!empty($item['properties'])) {
                            foreach ($item['properties'] as $prop) {
                                // 🚫 Skip bundle selection meta
                                if (!empty($prop['name']) && str_starts_with(strtolower($prop['name']), '_bundle_selection')) {
                                    continue;
                                }

                                if (!empty($prop['value'])) {
                                    $value = trim($prop['value']);
                                    $flavourRaw = '';
                                    $qty = 0;

                                    if (strpos($value, ':') !== false) {
                                        // Split at colon and clean up
                                        [$flavourRaw, $qtyPart] = explode(':', $value, 2);
                                        $flavourRaw = trim($flavourRaw);
                                        $qty = (int) filter_var($qtyPart, FILTER_SANITIZE_NUMBER_INT);
                                    } else {
                                        // Defaults if no colon found
                                        $flavourRaw = $value;
                                        switch ($productId) {
                                            case 7362831646754:
                                                $qty = 6;
                                                break;
                                            case 7362831974434:
                                                $qty = 4;
                                                break;
                                            case 7362832072738:
                                                $qty = 3;
                                                break;
                                            case 7362832269346:
                                                $qty = 2;
                                                break;
                                            default:
                                                $qty = 1;
                                                break;
                                        }
                                    }

                                    $flavourKey = strtolower($flavourRaw);
                                    $finalQty = $qty * $lineQty;

                                    if (!isset($flavours[$flavourKey])) {
                                        $flavours[$flavourKey] = [
                                            'flavour_name' => $flavourRaw,
                                            'quantity' => 0,
                                            'order_numbers' => [],
                                        ];
                                    }

                                    $flavours[$flavourKey]['quantity'] += $finalQty;
                                    $flavours[$flavourKey]['order_numbers'][$orderName] = $orderName;
                                }
                            }
                        }
                    }
                }
            }

            if (empty($flavours)) {
                return back()->with('error', 'No data found for export');
            }

            // ✅ Prepare CSV
            $serial = 1;
            $rows = [];
            foreach ($flavours as $flavour) {
                $rows[] = [
                    'serial_no' => $serial++,
                    'flavour_name' => $flavour['flavour_name'],
                    'quantity' => $flavour['quantity'],
                    'order_numbers' => implode(',', $flavour['order_numbers']),
                ];
            }

            $filename = "orders_" . now()->format('Y-m-d_H-i-s') . ".csv";
            $handle = fopen('php://output', 'w');
            ob_start();
            fputcsv($handle, ['serial_no', 'flavour_name', 'quantity', 'order_numbers']);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);

            $content = ob_get_clean();

            return response($content)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', "attachment; filename={$filename}");
        } catch (\Exception $e) {
            \Log::error("CSV Export failed: " . $e->getMessage());
            return back()->with('error', 'unknown error');
        }
    }
}
