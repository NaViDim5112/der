<?php
require __DIR__ . '/../../app/bootstrap.php';

require_post();
logout_user();
flash('success', 'Вы вышли из аккаунта.');
redirect(url('/forum/'));
