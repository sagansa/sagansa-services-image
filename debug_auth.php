<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== SANCTUM CONFIG ===\n";
echo "token_model: " . (config('sanctum.token_model') ?: 'default') . "\n";
echo "guard: " . json_encode(config('sanctum.guard')) . "\n";

echo "\n=== DB_AUTH CONFIG ===\n";
echo "host: " . config('database.connections.mysql_auth.host') . "\n";
echo "database: " . config('database.connections.mysql_auth.database') . "\n";
echo "username: " . config('database.connections.mysql_auth.username') . "\n";

echo "\n=== TOKEN 487 CHECK ===\n";
try {
    $token = \App\Models\PersonalAccessToken::find(487);
    if ($token) {
        echo "Token: FOUND (id={$token->id}, conn={$token->getConnectionName()})\n";
        $user = $token->tokenable;
        echo "User: " . ($user ? "FOUND (id={$user->id})" : 'NOT FOUND') . "\n";
    } else {
        echo "Token: NOT FOUND\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== DIRECT DB CHECK ===\n";
try {
    \Illuminate\Support\Facades\DB::connection('mysql_auth')->getPdo();
    echo "DB connection: OK\n";
    $count = \Illuminate\Support\Facades\DB::connection('mysql_auth')
        ->table('personal_access_tokens')->where('id', 487)->count();
    echo "Token 487 in DB: " . ($count > 0 ? 'EXISTS' : 'NOT FOUND') . "\n";
} catch (\Exception $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}
