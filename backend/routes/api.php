<?php
/**
 * Route table: [METHOD, /path/{param}, [Controller::class, 'method'], permission]
 * permission: 'public' = open; null = any authenticated user; else a permission code.
 *
 * Simplified app — only 5 features are exposed:
 *   login · daily egg records · money (hisab)
 * Old module routes were removed; controllers stay on disk unused.
 */
return [
    // ---------- auth ----------
    ['POST', '/auth/login',                 [AuthController::class, 'login'],          'public'],
    ['POST', '/auth/register',              [AuthController::class, 'register'],       'public'],
    ['POST', '/auth/forgot',                [AuthController::class, 'forgot'],         'public'],
    ['POST', '/auth/reset',                 [AuthController::class, 'reset'],          'public'],
    ['POST', '/auth/logout',                [AuthController::class, 'logout'],         null],
    ['GET',  '/auth/me',                    [AuthController::class, 'me'],             null],

    // ---------- daily egg records ----------
    ['GET',  '/layers/records',             [LayerController::class, 'records'],       null],
    ['POST', '/layers/records',             [LayerController::class, 'saveRecord'],    null],
    ['GET',  '/layers/records/{date}',      [LayerController::class, 'record'],        null],
    ['DELETE','/layers/records/{date}',     [LayerController::class, 'deleteRecord'],  null],
    ['GET',  '/layers/egg-stock',           [LayerController::class, 'eggStock'],      null],

    // ---------- money / हिसाब ----------
    ['GET',  '/money',                      [MoneyController::class, 'index'],         null],
    ['POST', '/money',                      [MoneyController::class, 'store'],         null],
    ['PUT',  '/money/{id}',                 [MoneyController::class, 'update'],        null],
    ['DELETE','/money/{id}',                [MoneyController::class, 'destroy'],       null],
    ['GET',  '/money/categories',           [MoneyController::class, 'categories'],    null],

    // ---------- raw material expenses ----------
    ['GET',  '/materials',                  [MaterialController::class, 'index'],            null],
    ['POST', '/materials',                  [MaterialController::class, 'store'],            null],
    ['PUT',  '/materials/{id}',             [MaterialController::class, 'update'],           null],
    ['DELETE','/materials/{id}',            [MaterialController::class, 'destroy'],          null],
    ['GET',  '/materials/totals',           [MaterialController::class, 'totals'],           null],
    ['GET',  '/materials/categories',       [MaterialController::class, 'categories'],       null],
    ['POST', '/materials/categories',       [MaterialController::class, 'storeCategory'],    null],
    ['PUT',  '/materials/categories/{id}',  [MaterialController::class, 'updateCategory'],   null],
    ['DELETE','/materials/categories/{id}', [MaterialController::class, 'destroyCategory'],  null],

    // ---------- उधारी / credit register ----------
    ['GET',  '/credit',                     [CreditController::class, 'index'],        null],
    ['POST', '/credit',                     [CreditController::class, 'store'],        null],
    ['PUT',  '/credit/{id}',                [CreditController::class, 'update'],       null],
    ['DELETE','/credit/{id}',               [CreditController::class, 'destroy'],      null],

    // ---------- per-user prefs (reminders) ----------
    ['GET',  '/prefs',                      [PrefsController::class, 'index'],         null],
    ['PUT',  '/prefs',                      [PrefsController::class, 'update'],        null],

    // ---------- backup & restore ----------
    ['GET',  '/backup',                     [BackupController::class, 'export'],       null],
    ['POST', '/backup/restore',             [BackupController::class, 'restore'],      null],

    // ---------- admin: user management ----------
    ['GET',  '/users',                      [UserController::class, 'index'],          'users.manage'],
    ['POST', '/users',                      [UserController::class, 'store'],          'users.manage'],
    ['PUT',  '/users/{id}',                 [UserController::class, 'update'],         'users.manage'],
    ['DELETE','/users/{id}',                [UserController::class, 'destroy'],        'users.manage'],
    ['GET',  '/roles',                      [UserController::class, 'roles'],          'users.manage'],
];
