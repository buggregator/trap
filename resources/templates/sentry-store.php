<div class="mt-2">
    <table>
        <tr>
            <th>date</th>
            <td><?= $date ?></td>
        </tr>
        <tr>
            <th>type</th>
            <td><?= $type ?></td>
        </tr>
    </table>
`
    <h1 class="font-bold text-white my-1 bg-green px-1">Sentry</h1>
    <h2 class="font-bold"><?= $type ?></h2>
    <p>
        <span class="text-red"><?= $message ?></span>
        <span class="text-gray px-1">on line</span>
        <span class="font-bold"><?= $codeSnippet['file'] ?></span>:<span
            class="text-yellow font-bold"><?= $codeSnippet['line'] ?></span>
    </p>

    <?php
    if (!empty($codeSnippet['content'])): ?>
        <hr>
        <code line="<?= $codeSnippet['line'] ?>" start-line="<?= $codeSnippet['start_line'] ?>">
            <?= $codeSnippet['content'] ?>
        </code>
    <?php
    endif; ?>

    <hr>

    <div class="mt-1">
        <?php
        foreach ($trace as $pos => $line): ?>
            <?php
            if (is_array($line)): ?>
                <div>
                    <span class="text-gray ml-<?= 3 - strlen($pos) ?>"><?= $pos ?></span>.
                    <span class="font-bold"><?= $line['file'] ?></span>:<span class="font-bold"><?= $line['line'] ?>
                </div>
                <div>
                    <span class="text-gray ml-5"><?= $line['class'] ?><?= $line['function'] ?></span>
                </div>
            <?php else: ?>
                <div class="mt-1">
                    <span class="font-bold text-blue ml-5"><?= $line ?>></span>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
