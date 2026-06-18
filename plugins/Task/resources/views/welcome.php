<?php /** @var string $name */ ?>
<h1>Task plugin says hello, <?= htmlspecialchars($name ?? 'world', ENT_QUOTES, 'UTF-8') ?></h1>
<p>This view ships INSIDE the Task plugin (resolved as <code>task::welcome</code>).</p>
