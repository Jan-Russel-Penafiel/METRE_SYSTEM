<?php

require_once __DIR__ . '/../includes/auth.php';

require_login(['admin']);

$editingId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editingSetting = $editingId > 0 ? find_fare_setting_by_id($editingId) : null;

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

    if (fare_setting_exists_for_vehicle($vehicleType, $id)) {
        set_flash('That vehicle type already has a fare rule.', 'error');
        redirect_to('admin/fare_settings.php' . ($id ? '?edit=' . $id : ''));
    }

    save_fare_setting_record($id, [
        'vehicle_type' => $vehicleType,
        'rate_per_meter' => $ratePerMeter,
        'minimum_fare' => $minimumFare,
        'night_surcharge_percent' => $nightSurcharge,
        'waiting_rate_per_minute' => $waitingRate,
    ]);

    set_flash($id > 0 ? 'Fare setting updated.' : 'Fare setting saved.', 'success');
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
    <section class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
        <div class="mb-6">
            <h1 class="text-3xl font-bold tracking-tight">Fare configuration</h1>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Maintain per-vehicle fare rules used by both the live meter and finalized receipts.</p>
        </div>

        <form method="post" class="space-y-5">
            <input type="hidden" name="id" value="<?php echo (int) $editingSetting['id']; ?>">

            <div>
                <label for="vehicle_type" class="mb-1.5 block text-sm font-medium">Vehicle type</label>
                <select id="vehicle_type" name="vehicle_type" class="block w-full rounded-md border border-zinc-200 px-3 py-2 focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950">
                    <?php foreach ($vehicleTypeOptions as $option): ?>
                        <option value="<?php echo h($option); ?>" <?php echo $editingSetting['vehicle_type'] === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="rate_per_meter" class="mb-1.5 block text-sm font-medium">Rate per meter</label>
                    <input id="rate_per_meter" name="rate_per_meter" type="number" step="0.01" min="0" value="<?php echo h($editingSetting['rate_per_meter']); ?>" class="block w-full rounded-md border border-zinc-200 px-3 py-2 focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950" required>
                </div>
                <div>
                    <label for="minimum_fare" class="mb-1.5 block text-sm font-medium">Minimum fare</label>
                    <input id="minimum_fare" name="minimum_fare" type="number" step="0.01" min="0" value="<?php echo h($editingSetting['minimum_fare']); ?>" class="block w-full rounded-md border border-zinc-200 px-3 py-2 focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950" required>
                </div>
                <div>
                    <label for="night_surcharge_percent" class="mb-1.5 block text-sm font-medium">Night surcharge (%)</label>
                    <input id="night_surcharge_percent" name="night_surcharge_percent" type="number" step="0.01" min="0" value="<?php echo h($editingSetting['night_surcharge_percent']); ?>" class="block w-full rounded-md border border-zinc-200 px-3 py-2 focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950" required>
                </div>
                <div>
                    <label for="waiting_rate_per_minute" class="mb-1.5 block text-sm font-medium">Waiting rate / minute</label>
                    <input id="waiting_rate_per_minute" name="waiting_rate_per_minute" type="number" step="0.01" min="0" value="<?php echo h($editingSetting['waiting_rate_per_minute']); ?>" class="block w-full rounded-md border border-zinc-200 px-3 py-2 focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950" required>
                </div>
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-50 dark:text-zinc-900 dark:hover:bg-zinc-200">
                    <?php echo $editingSetting['id'] ? 'Update Fare Rule' : 'Save Fare Rule'; ?>
                </button>
                <?php if ($editingSetting['id']): ?>
                    <a href="<?php echo h(url('admin/fare_settings.php')); ?>" class="rounded-md border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-6 py-5 dark:border-zinc-800">
            <h2 class="text-xl font-bold tracking-tight">Current fare rules</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Vehicle</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Rate/m</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Minimum</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Waiting</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Night</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    <?php if (!$settings): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-zinc-500 dark:text-zinc-400">No fare settings found. Save the first rule to activate the meter.</td>
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
                                <a href="<?php echo h(url('admin/fare_settings.php?edit=' . (int) $setting['id'])); ?>" class="text-zinc-900 hover:text-zinc-700 dark:text-zinc-100">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php render_page_end(); ?>
