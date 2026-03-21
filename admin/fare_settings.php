<?php

require_once __DIR__ . '/../includes/auth.php';

require_login(['admin']);

$editingId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editingSetting = $editingId > 0 ? db_select_one('SELECT * FROM fare_settings WHERE id = ? LIMIT 1', 'i', [$editingId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $vehicleType = trim((string) ($_POST['vehicle_type'] ?? ''));
    $ratePerMeter = max(0, (float) ($_POST['rate_per_meter'] ?? 0));
    $minimumFare = max(0, (float) ($_POST['minimum_fare'] ?? 0));
    $nightSurcharge = max(0, (float) ($_POST['night_surcharge_percent'] ?? 0));
    $waitingRate = max(0, (float) ($_POST['waiting_rate_per_minute'] ?? 0));

    if ($vehicleType === '') {
        set_flash('Vehicle type is required.', 'error');
        redirect_to('admin/fare_settings.php');
    }

    $duplicate = db_select_one(
        'SELECT id FROM fare_settings WHERE vehicle_type = ? AND id <> ? LIMIT 1',
        'si',
        [$vehicleType, $id]
    );

    if ($duplicate) {
        set_flash('That vehicle type already has a fare rule.', 'error');
        redirect_to('admin/fare_settings.php' . ($id ? '?edit=' . $id : ''));
    }

    if ($id > 0) {
        db_execute(
            'UPDATE fare_settings SET vehicle_type = ?, rate_per_meter = ?, minimum_fare = ?, night_surcharge_percent = ?, waiting_rate_per_minute = ?, updated_at = NOW() WHERE id = ?',
            'sddddi',
            [$vehicleType, $ratePerMeter, $minimumFare, $nightSurcharge, $waitingRate, $id]
        );
        set_flash('Fare setting updated.', 'success');
    } else {
        db_execute(
            'INSERT INTO fare_settings (vehicle_type, rate_per_meter, minimum_fare, night_surcharge_percent, waiting_rate_per_minute, updated_at) VALUES (?, ?, ?, ?, ?, NOW())',
            'sdddd',
            [$vehicleType, $ratePerMeter, $minimumFare, $nightSurcharge, $waitingRate]
        );
        set_flash('Fare setting saved.', 'success');
    }

    redirect_to('admin/fare_settings.php');
}

$settings = get_fare_settings();
$vehicleTypeOptions = get_vehicle_type_options();

if (!$editingSetting) {
    $editingSetting = [
        'id' => 0,
        'vehicle_type' => $vehicleTypeOptions[0],
        'rate_per_meter' => 0.15,
        'minimum_fare' => 40,
        'night_surcharge_percent' => 20,
        'waiting_rate_per_minute' => 2,
    ];
}

render_page_start('Fare Settings');
?>
<div class="grid gap-8 xl:grid-cols-[0.95fr_1.05fr]">
    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-8">
        <div class="mb-6">
            <h1 class="text-3xl font-black tracking-tight">Fare configuration</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Maintain per-vehicle fare rules used by both the live meter and finalized receipts.</p>
        </div>

        <form method="post" class="space-y-5">
            <input type="hidden" name="id" value="<?php echo (int) $editingSetting['id']; ?>">

            <div>
                <label for="vehicle_type" class="mb-2 block text-sm font-medium">Vehicle type</label>
                <select id="vehicle_type" name="vehicle_type" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950">
                    <?php foreach ($vehicleTypeOptions as $option): ?>
                        <option value="<?php echo h($option); ?>" <?php echo $editingSetting['vehicle_type'] === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="rate_per_meter" class="mb-2 block text-sm font-medium">Rate per meter</label>
                    <input id="rate_per_meter" name="rate_per_meter" type="number" step="0.01" min="0" value="<?php echo h($editingSetting['rate_per_meter']); ?>" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950" required>
                </div>
                <div>
                    <label for="minimum_fare" class="mb-2 block text-sm font-medium">Minimum fare</label>
                    <input id="minimum_fare" name="minimum_fare" type="number" step="0.01" min="0" value="<?php echo h($editingSetting['minimum_fare']); ?>" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950" required>
                </div>
                <div>
                    <label for="night_surcharge_percent" class="mb-2 block text-sm font-medium">Night surcharge (%)</label>
                    <input id="night_surcharge_percent" name="night_surcharge_percent" type="number" step="0.01" min="0" value="<?php echo h($editingSetting['night_surcharge_percent']); ?>" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950" required>
                </div>
                <div>
                    <label for="waiting_rate_per_minute" class="mb-2 block text-sm font-medium">Waiting rate / minute</label>
                    <input id="waiting_rate_per_minute" name="waiting_rate_per_minute" type="number" step="0.01" min="0" value="<?php echo h($editingSetting['waiting_rate_per_minute']); ?>" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950" required>
                </div>
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-2xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white hover:bg-sky-700">
                    <?php echo $editingSetting['id'] ? 'Update Fare Rule' : 'Save Fare Rule'; ?>
                </button>
                <?php if ($editingSetting['id']): ?>
                    <a href="<?php echo h(url('admin/fare_settings.php')); ?>" class="rounded-2xl border border-slate-300 px-5 py-3 text-sm font-semibold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-slate-200 px-6 py-5 dark:border-slate-800">
            <h2 class="text-xl font-bold tracking-tight">Current fare rules</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <thead class="bg-slate-50 dark:bg-slate-950/50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Vehicle</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Rate/m</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Minimum</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Waiting</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Night</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php if (!$settings): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-slate-500 dark:text-slate-400">No fare settings found. Save the first rule to activate the meter.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($settings as $setting): ?>
                        <tr>
                            <td class="px-6 py-4 font-medium"><?php echo h($setting['vehicle_type']); ?></td>
                            <td class="px-6 py-4"><?php echo h(format_currency($setting['rate_per_meter'])); ?></td>
                            <td class="px-6 py-4"><?php echo h(format_currency($setting['minimum_fare'])); ?></td>
                            <td class="px-6 py-4"><?php echo h(format_currency($setting['waiting_rate_per_minute'])); ?></td>
                            <td class="px-6 py-4"><?php echo h(number_format((float) $setting['night_surcharge_percent'], 2)); ?>%</td>
                            <td class="px-6 py-4">
                                <a href="<?php echo h(url('admin/fare_settings.php?edit=' . (int) $setting['id'])); ?>" class="text-sky-600 hover:text-sky-700 dark:text-sky-400">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php render_page_end(); ?>
