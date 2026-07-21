<?php

namespace Database\Seeders;

use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Grn;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Master;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\SalesOrder;
use App\Models\SalesRep;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->seedCompany();
        $this->seedUser();
        $items = $this->seedItems();
        $customers = $this->seedCustomers();
        $this->seedReps();
        $this->seedMasters();
        $this->seedSuppliers();
        $this->seedTransactions($items, $customers);
    }

    private function seedCompany()
    {
        CompanyProfile::updateOrCreate(['id' => 1], [
            'name' => 'Distributor Management System',
            'tagline' => 'Distribution & Supply Chain Management',
            'address' => 'No. 10, Main Street, Colombo 01, Sri Lanka',
            'phone' => '+94 11 000 0000',
            'email' => 'info@dms.lk',
            'tax_no' => 'VAT-000-0000-0000',
            'currency' => 'LKR — Sri Lankan Rupee',
            'accent' => 'blue',
        ]);
    }

    private function seedUser()
    {
        // Only set on first creation — re-running the seeder (e.g. via the
        // ops migrate endpoint) must never clobber a real admin's password
        // back to this default.
        if (User::where('email', 'admin@mms-auto.lk')->exists()) {
            return;
        }

        User::create([
            'email' => 'admin@mms-auto.lk',
            'name' => 'Admin User',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }

    private function seedItems()
    {
        $branches = ['Main Store', 'City Branch', 'Highway Depot'];
        $groups = ['Genuine', 'OEM', 'Aftermarket', 'Reconditioned'];
        $rows = [
            ['Brake Pad Set Front', 'Brakes', 'Toyota', 'BP-2201'],
            ['Brake Disc Rotor', 'Brakes', 'Nissan', 'BD-3310'],
            ['Oil Filter', 'Filters', 'Toyota', 'OF-1120'],
            ['Air Filter Element', 'Filters', 'Honda', 'AF-4420'],
            ['Spark Plug Iridium', 'Electrical', 'Suzuki', 'SP-7781'],
            ['Timing Belt Kit', 'Engine', 'Mitsubishi', 'TB-9920'],
            ['Shock Absorber Rear', 'Suspension', 'Toyota', 'SA-5540'],
            ['Clutch Plate Assembly', 'Transmission', 'Isuzu', 'CP-6610'],
            ['Radiator Assembly', 'Cooling', 'Nissan', 'RD-8830'],
            ['Alternator 12V', 'Electrical', 'Honda', 'AL-2245'],
            ['Wheel Bearing Kit', 'Suspension', 'Mazda', 'WB-3367'],
            ['Fuel Pump Assembly', 'Engine', 'Toyota', 'FP-1199'],
            ['Headlamp Assembly RH', 'Body', 'Suzuki', 'HL-7720'],
            ['Wiper Blade 22"', 'Body', 'Hyundai', 'WP-9981'],
            ['Engine Mount', 'Engine', 'Nissan', 'EM-4456'],
            ['CV Joint Boot Kit', 'Transmission', 'Toyota', 'CV-3322'],
            ['Battery 65Ah', 'Electrical', 'Mitsubishi', 'BT-5500'],
            ['Coolant Hose Upper', 'Cooling', 'Honda', 'CH-2278'],
            ['Tie Rod End', 'Suspension', 'Isuzu', 'TR-6643'],
            ['Cabin Air Filter', 'Filters', 'Mazda', 'CF-8814'],
            ['Drive Belt', 'Engine', 'Toyota', 'DB-1290'],
            ['Brake Master Cylinder', 'Brakes', 'Nissan', 'MC-7702'],
            ['Ignition Coil', 'Electrical', 'Suzuki', 'IC-3340'],
            ['Water Pump', 'Cooling', 'Honda', 'WP-5521'],
        ];
        $items = collect();
        foreach ($rows as $i => $r) {
            [$name, $cat, $brand, $code] = $r;
            $cost = round((800 + mt_rand(0, 14000)) / 10) * 10;
            $margin = 1.18 + mt_rand(0, 35) / 100;
            $reorder = 12;
            $force = $i % 7 === 3 ? 0 : ($i % 5 === 2 ? mt_rand(2, 9) : null);
            $stock = $force !== null
                ? [$force, $force > 0 ? intval($force / 2) : 0, 0]
                : array_map(fn () => 14 + mt_rand(0, 55), $branches);
            $qty = array_sum($stock);
            $items->push(Item::create([
                'code' => $code, 'name' => $name, 'category' => $cat, 'brand' => $brand,
                'group' => $groups[$i % 4], 'unit' => ['Pcs', 'Set', 'Kit', 'Box'][$i % 4],
                'avg_cost' => $cost, 'fifo_cost' => round($cost * (0.96 + mt_rand(0, 8) / 100)),
                'price' => round($cost * $margin / 10) * 10, 'qty' => $qty, 'reorder' => $reorder,
                'stock_by_branch' => $stock,
                'rack' => 'R' . (1 + $i % 8) . '-' . ['A', 'B', 'C', 'D'][$i % 4] . (1 + $i % 6),
                'status' => $qty === 0 ? 'out' : ($qty <= $reorder ? 'low' : 'in'),
            ]));
        }

        return $items;
    }

    private function seedCustomers()
    {
        $names = ['Lanka Motors', 'Speedway Garage', 'AutoCare Center', 'Highway Spares', 'Galaxy Vehicles',
            'Prime Auto Works', 'Metro Service Hub', 'Royal Motors', 'Apex Garage', 'Silverline Autos',
            'City Wheels', 'Turbo Tech Center'];
        $reps = ['R. Fernando', 'S. Perera', 'M. Iqbal', 'D. Silva'];
        $customers = collect();
        foreach ($names as $i => $n) {
            $limit = [200000, 500000, 150000, 300000, 750000][$i % 5];
            $out = round(mt_rand(0, 80) / 100 * $limit);
            $city = ['Colombo', 'Kandy', 'Galle', 'Negombo', 'Jaffna'][$i % 5];
            $customers->push(Customer::create([
                'code' => 'C' . (2200 + $i), 'name' => $n,
                'company_name' => $n . ' (Pvt) Ltd',
                'contact' => '07' . (10000000 + mt_rand(0, 8999999)),
                'address' => 'No. ' . (10 + $i) . ', Main Street, ' . $city,
                'email' => strtolower(str_replace(' ', '.', $n)) . '@example.lk',
                'credit' => [7, 15, 30, 45][$i % 4], 'limit' => $limit, 'outstanding' => $out,
                'rep' => $reps[$i % 4], 'status' => $out > $limit * 0.7 ? 'risk' : 'ok',
            ]));
        }

        return $customers;
    }

    private function seedReps()
    {
        $data = [
            ['REP-01', 'R. Fernando', 'Colombo West', 1200000, 945000, 38, 62, 'RF'],
            ['REP-02', 'S. Perera', 'Kandy Central', 900000, 1020000, 41, 55, 'SP'],
            ['REP-03', 'M. Iqbal', 'Galle South', 800000, 610000, 29, 44, 'MI'],
            ['REP-04', 'D. Silva', 'Negombo North', 750000, 702000, 33, 48, 'DS'],
        ];
        $reps = collect();
        foreach ($data as $r) {
            $reps->push(SalesRep::create([
                'code' => $r[0], 'name' => $r[1], 'zone' => $r[2], 'target' => $r[3],
                'achieved' => $r[4], 'visits' => $r[5], 'invoices' => $r[6], 'avatar' => $r[7],
                'login' => strtolower(str_replace(' ', '.', $r[1])) . '@mms', 'app_enabled' => true,
            ]));
        }

        return $reps;
    }

    private function seedMasters()
    {
        $categories = ['Engine', 'Brakes', 'Suspension', 'Electrical', 'Filters', 'Body', 'Transmission', 'Cooling'];
        foreach (['Denso', 'Bosch', 'NGK', 'Aisin', 'Koyo', 'Exedy', 'Sakura', 'Valeo'] as $i => $b) {
            Master::create(['type' => 'brand', 'code' => 'B' . (10 + $i), 'name' => $b, 'status' => 'Active', 'data' => ['category' => $categories[$i % count($categories)]]]);
        }

        foreach ([['Wheel Alignment', 2500], ['Engine Tune-up', 6500], ['Brake Service', 3500], ['AC Gas Refill', 4500], ['Battery Check', 800], ['Diagnostic Scan', 1500]] as $i => $s) {
            Master::create(['type' => 'services', 'code' => 'S' . (10 + $i), 'name' => $s[0], 'status' => 'Active', 'data' => ['rate' => $s[1]]]);
        }

        foreach ([['Cash', 'Cash'], ['Bank Transfer', 'Bank'], ['Credit Card', 'Card'], ['Cheque', 'Cheque'], ['Credit Sale', 'Credit']] as $i => $p) {
            Master::create(['type' => 'payment', 'code' => 'P' . (10 + $i), 'name' => $p[0], 'status' => 'Active', 'data' => ['type' => $p[1]]]);
        }
        foreach ([['Commercial Bank', '8001234567', 'Colombo'], ['Sampath Bank', '1052009988', 'Kollupitiya'], ['HNB', '0710456321', 'Kandy'], ['BOC', '7745001122', 'Galle']] as $i => $b) {
            Master::create(['type' => 'bank', 'code' => 'BK' . (10 + $i), 'name' => $b[0], 'status' => 'Active', 'data' => ['account' => $b[1], 'branch' => $b[2]]]);
        }

        $bi = 0;
        foreach (['Main Store' => 'Colombo', 'City Branch' => 'Kandy', 'Highway Depot' => 'Galle'] as $name => $city) {
            Master::create(['type' => 'branch', 'code' => 'BR' . (10 + $bi), 'name' => $name, 'status' => 'Active', 'data' => ['city' => $city, 'phone' => '011' . (2340000 + $bi * 111)]]);
            $bi++;
        }
        foreach ([['Fuel', '5101'], ['Salary', '5102'], ['Rent', '5103'], ['Utilities', '5104'], ['Transport', '5105'], ['Maintenance', '5106'], ['Misc', '5199']] as $i => $e) {
            Master::create(['type' => 'expenseType', 'code' => 'E' . (10 + $i), 'name' => $e[0], 'status' => 'Active', 'data' => ['account' => $e[1]]]);
        }
        foreach ([['Cash / COD', 0], ['Net 7', 7], ['Net 15', 15], ['Net 30', 30], ['Net 45', 45], ['Net 60', 60]] as $i => $c) {
            Master::create(['type' => 'credit', 'code' => 'CP' . (10 + $i), 'name' => $c[0], 'status' => 'Active', 'data' => ['days' => $c[1]]]);
        }
        foreach (['Goods once sold cannot be returned without approval.', 'Warranty as per manufacturer terms only.', 'Please verify part number before fitting.', 'Payment due within agreed credit period.'] as $i => $t) {
            Master::create(['type' => 'remark', 'code' => 'RM' . (10 + $i), 'name' => $t, 'status' => 'Active', 'data' => ['text' => $t]]);
        }
        foreach ([['Ruwan Fernando', 'Sales', 'Sales Manager'], ['Kasun Bandara', 'Stores', 'Store Keeper'], ['Nimal Jayasuriya', 'Finance', 'Accountant'], ['Ahmed Razik', 'Procurement', 'Buyer'], ['Sanduni Mendis', 'Admin', 'HR Officer'], ['Pradeep Silva', 'Sales', 'Sales Executive']] as $i => $e) {
            Master::create(['type' => 'employee', 'code' => 'EMP-' . (101 + $i), 'name' => $e[0], 'status' => 'Active', 'data' => ['department' => $e[1], 'role' => $e[2], 'contact' => '07' . (11000000 + $i * 7654)]]);
        }
    }

    private function seedSuppliers()
    {
        $rows = [
            ['SUP-01', 'Toyota Lanka PLC', '+94 11 277 7888', 'sales@toyota.lk', 'Colombo', 'Sri Lanka', 'VAT-101-1001', 'DA 30 Days', 'LKR', 'No. 75, Buthgamuwa Rd, Rajagiriya'],
            ['SUP-02', 'United Motors', '+94 11 286 6611', 'info@unimo.lk', 'Colombo', 'Sri Lanka', 'VAT-101-1002', 'DA 60 Days', 'LKR', 'No. 100, Hyde Park Corner, Colombo 02'],
            ['SUP-03', 'Diesel & Motor Eng.', '+94 11 234 0000', 'parts@dimo.lk', 'Colombo', 'Sri Lanka', 'VAT-101-1003', 'DP at sight', 'LKR', 'P.O. Box 339, Galle Road, Colombo 03'],
            ['SUP-04', 'AutoMart Imports', '+81 3 6779 4400', 'exports@automart.jp', 'Tokyo', 'Japan', 'JP-220-998', 'LC at sight', 'JPY', '2-3-1 Marunouchi, Chiyoda-ku, Tokyo'],
            ['SUP-05', 'Global Parts Co', '+1 313 555 0144', 'orders@globalparts.com', 'Detroit', 'United States', 'EIN-44-7788001', '100% TT in advance', 'USD', '500 Renaissance Center, Detroit, MI'],
            ['SUP-06', 'Nippon Trading', '+81 6 6233 1100', 'info@nippon-trading.jp', 'Osaka', 'Japan', 'JP-441-227', 'DA 60 Days', 'JPY', '3-5-12 Honmachi, Chuo-ku, Osaka'],
        ];
        foreach ($rows as $r) {
            Supplier::updateOrCreate(['code' => $r[0]], [
                'name' => $r[1], 'contact' => $r[2], 'email' => $r[3], 'city' => $r[4],
                'country' => $r[5], 'tax_no' => $r[6], 'payment_terms' => $r[7],
                'currency' => $r[8], 'address' => $r[9], 'status' => 'Active',
            ]);
        }
    }

    private function seedTransactions($items, $customers)
    {
        $suppliers = ['Toyota Lanka PLC', 'United Motors', 'Diesel & Motor Eng.', 'AutoMart Imports', 'Global Parts Co', 'Nippon Trading'];
        $today = '2026-06-09';
        $dayOff = fn ($n) => date('Y-m-d', strtotime("$today -$n days"));

        $adminId = User::where('email', 'admin@mms-auto.lk')->value('id');

        for ($i = 0; $i < 8; $i++) {
            $status = ['Pending', 'Approved', 'Partial GRN', 'Completed'][$i % 4];
            $approved = $status !== 'Pending';
            $po = PurchaseOrder::create([
                'code' => 'PO-' . (4400 + $i), 'supplier' => $suppliers[$i % count($suppliers)],
                'date' => $dayOff($i * 2), 'total' => 0,
                'status' => $status,
                'approver1_id' => $approved ? $adminId : null,
                'approver1_at' => $approved ? $dayOff($i * 2) : null,
            ]);
            $total = 0;
            for ($j = 0; $j < 2 + ($i % 3); $j++) {
                $it = $items[($i + $j) % $items->count()]; $q = 5 + mt_rand(0, 39);
                $received = $status === 'Completed' ? $q : ($status === 'Partial GRN' ? intdiv($q, 2) : 0);
                $po->lines()->create([
                    'code' => $it->code, 'item' => $it->name, 'qty' => $q,
                    'balance_qty' => $q - $received, 'received_qty' => $received,
                    'cost' => $it->avg_cost, 'total' => $q * $it->avg_cost,
                ]);
                $total += $q * $it->avg_cost;
            }
            $po->update(['total' => $total]);
        }

        for ($i = 0; $i < 6; $i++) {
            Grn::create(['code' => 'GRN-' . (7700 + $i), 'po' => 'PO-' . (4400 + $i), 'supplier' => $suppliers[$i % count($suppliers)],
                'date' => $dayOff(max(0, $i * 2 - 1)), 'items' => 2 + ($i % 4), 'total' => mt_rand(80000, 420000),
                'status' => ['Posted', 'Posted', 'Draft'][$i % 3]]);
        }

        for ($i = 0; $i < 10; $i++) {
            $c = $customers[$i % $customers->count()];
            Quotation::create(['code' => 'QT-' . (9100 + $i), 'customer' => $c->name, 'rep' => $c->rep, 'date' => $dayOff($i),
                'total' => mt_rand(25000, 380000), 'items' => 1 + ($i % 6),
                'status' => ['Open', 'Converted', 'Open', 'Expired'][$i % 4], 'cost' => ['FIFO', 'Average'][$i % 2]]);
        }

        for ($i = 0; $i < 12; $i++) {
            $c = $customers[$i % $customers->count()];
            $total = mt_rand(18000, 420000);
            $paid = [$total, $total, intval($total * 0.5), 0][$i % 4];
            Invoice::create(['code' => 'INV-' . (5500 + $i), 'customer' => $c->name, 'rep' => $c->rep, 'date' => $dayOff($i),
                'total' => $total, 'paid' => $paid, 'due' => $total - $paid, 'items' => 1 + ($i % 7),
                'cost' => ['FIFO', 'Average'][$i % 2], 'status' => $paid >= $total ? 'Paid' : ($paid > 0 ? 'Partial' : 'Unpaid')]);
        }

        for ($i = 0; $i < 5; $i++) {
            SalesReturn::create(['code' => 'SR-' . (3300 + $i), 'invoice' => 'INV-' . (5500 + $i),
                'customer' => $customers[$i % $customers->count()]->name, 'rep' => 'R. Fernando', 'date' => $dayOff($i),
                'amount' => mt_rand(5000, 80000), 'items' => 1 + ($i % 3),
                'reason' => ['Wrong part', 'Defective', 'Excess order', 'Damaged in transit'][$i % 4],
                'status' => ['Pending Approval', 'Pending Approval', 'Approved', 'Rejected'][$i % 4],
                'raised_by' => ['R. Fernando', 'S. Perera', 'M. Iqbal'][$i % 3]]);
        }

        for ($i = 0; $i < 7; $i++) {
            Receipt::create(['code' => 'RCP-' . (6600 + $i), 'customer' => $customers[$i % $customers->count()]->name,
                'date' => $dayOff($i), 'amount' => mt_rand(15000, 250000),
                'mode' => ['Cash', 'Cheque', 'Bank Transfer', 'Card'][$i % 4],
                'against' => $i % 3 === 0 ? 'On Account' : 'INV-' . (5500 + $i)]);
        }

        for ($i = 0; $i < 6; $i++) {
            Expense::create(['code' => 'EXP-' . (2100 + $i), 'type' => ['Fuel', 'Salary', 'Rent', 'Utilities', 'Transport', 'Misc'][$i % 6],
                'date' => $dayOff($i * 2), 'amount' => mt_rand(5000, 120000),
                'branch' => ['Main Store', 'City Branch', 'Highway Depot'][$i % 3], 'note' => '']);
        }

        // Sales orders raised by reps in the field, awaiting admin conversion.
        $repNames = ['R. Fernando', 'S. Perera', 'M. Iqbal', 'D. Silva'];
        for ($i = 0; $i < 5; $i++) {
            $c = $customers[$i % $customers->count()];
            $so = SalesOrder::create([
                'code' => 'SO-' . (8000 + $i),
                'customer' => $c->name,
                'rep' => $repNames[$i % count($repNames)],
                'date' => $dayOff($i),
                'source' => 'app',
                'status' => 'Pending',
                'total' => 0,
                'items' => 0,
            ]);
            $total = 0;
            $count = 2 + ($i % 3);
            for ($j = 0; $j < $count; $j++) {
                $it = $items[($i + $j) % $items->count()];
                $qty = 1 + mt_rand(1, 6);
                $so->lines()->create(['code' => $it->code, 'name' => $it->name, 'qty' => $qty, 'rate' => $it->price]);
                $total += $qty * $it->price;
            }
            $so->update(['total' => $total, 'items' => $count]);
        }
    }
}
