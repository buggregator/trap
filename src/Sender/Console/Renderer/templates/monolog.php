<div class="mt-2">
    <table>
        <tr>
            <th>date</th>
            <td><?= $date ?></td>
        </tr>
        <tr>
            <th>channel</th>
            <td><?= $channel ?></td>
        </tr>
    </table>

    <h1 class="font-bold text-white my-1">
        <span class="bg-blue px-1"><strong>MONOLOG</strong></span>
        <span class="px-1 bg-<?= $levelColor ?>"><?= $level ?></span>
    </h1>

    <div class="mb-1">
        <?php foreach ($messages as $message) : ?>
        <div class="font-bold text-<?= $levelColor ?>-500"><?= $message ?></div>
        <?php endforeach; ?>
    </div>
</div>
