<div class="mt-2">
    <table>
        <tr>
            <th>date</th>
            <td><?= $date ?></td>
        </tr>
    </table>

    <h1 class="font-bold bg-blue text-white my-1 px-1">SMTP</h1>
    <h2 class="font-bold mb-2"><?= $subject ?></h2>

    <div class="mb-2">
        <?php if($addresses !== []): ?>
        <table>
            <thead title="Addresses"></thead>
            <?php $i = 1 ?>
            <?php foreach($addresses as $type => $users): ?>
            <tbody>
            <tr>
                <th class="uppercase"><?= $type ?></th>
                <td><?= \implode(", ", $users) ?></td>
            </tr>
            </tbody>
            <?php endforeach ?>
        </table>
        <?php endif ?>
    </div>

    <code><?= $body ?></code>
</div>
