<?php

use KobaltDigital\PingPong\Machine\Users;

it('reads the users of /etc/passwd', function () {
    expect((new Users)->parsePasswd("root:x:0:0:root:/root:/bin/bash\n# comment\nforge:x:1000:1000::/home/forge:/bin/bash\nbroken:x:abc\n"))
        ->toBe([0 => 'root', 1000 => 'forge']);
});

it('names a user from /etc/passwd without ext-posix, and keeps the number of one it cannot find', function () {
    $passwd = tempnam(sys_get_temp_dir(), 'passwd');
    file_put_contents($passwd, "forge:x:1000:1000::/home/forge:/bin/bash\n");

    $users = new Users($passwd, usePosix: false);

    expect($users->name(1000))->toBe('forge')
        ->and($users->name(4242))->toBe('4242');
});
