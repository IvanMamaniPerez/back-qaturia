<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CRM Owner
    |--------------------------------------------------------------------------
    |
    | This value relates to the primary owner for the crm. It must be set as
    | the email address for the user registered in the users table so that you
    | can access the crm initially. You will need to register this user after
    | the crm is installed if not already.
    |
    */

    'crm_owner' => env('LARAVEL_CRM_OWNER', ''),

    /*
    |--------------------------------------------------------------------------
    | Teams Support
    |--------------------------------------------------------------------------
    |
    | Enables SaaS business isolation. Each internal user belongs to one
    | App\Models\Business through users.team_id. Functional roles are shared;
    | their assignments carry the business ID. No Jetstream dependency or
    | business switching is required. See docs/MULTIEMPRESA.md for migration.
    |
    | CRM teams are internal groups of employees within the same business.
    |
    */

    'teams' => env('LARAVEL_CRM_TEAMS', true),

    /*
    |--------------------------------------------------------------------------
    | Host Team Model
    |--------------------------------------------------------------------------
    |
    | Legacy package compatibility option. SaaS registration provisions
    | App\Models\Business; users cannot create or switch host teams.
    |
    */

    'host_team_model' => env('LARAVEL_CRM_HOST_TEAM_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | Default Settings
    |--------------------------------------------------------------------------
    |
    | Installation defaults inherited by businesses until overridden in
    | their settings. Corporate identity is stored on Business instead.
    |
    */

    'currency' => env('LARAVEL_CRM_CURRENCY', 'PEN'),

    'country' => env('LARAVEL_CRM_COUNTRY', 'Peru'),

    'language' => env('LARAVEL_CRM_LANGUAGE', 'spanish'),

    'timezone' => env('LARAVEL_CRM_TIMEZONE', 'America/Lima'),

    'date_format' => env('LARAVEL_CRM_DATE_FORMAT', 'd/m/Y'),

    'time_format' => env('LARAVEL_CRM_TIME_FORMAT', 'H:i'),

    'tax_name' => env('LARAVEL_CRM_TAX_NAME', 'IGV'),

    'tax_rate' => env('LARAVEL_CRM_TAX_RATE', 18),

    /*
    |--------------------------------------------------------------------------
    | Route Domain
    |--------------------------------------------------------------------------
    |
    | The full host the crm is served from, eg. crm.yourdomain.com.au. This is
    | the recommended setting for a subdomain install. When set it wins over
    | route_subdomain: the crm, login and API routes are bound to exactly this
    | host, so `php artisan route:cache`, queue workers and generated URLs no
    | longer depend on the host of whatever request booted the app.
    |
    | With route_prefix empty, GET and HEAD requests for the bare domain or
    | www. (eg. yourdomain.com.au/leads) redirect to the same path and query
    | on this host. API paths and the public /p links are never redirected.
    |
    | Leave it empty to keep working the host out from route_subdomain below.
    |
    */

    'route_domain' => env('LARAVEL_CRM_ROUTE_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Route Subdomain
    |--------------------------------------------------------------------------
    |
    | This value is used to define whether you wish to use the crm on a subdomain
    |
    | eg. https://subdomain.yourdomain.com
    |
    | The domain is worked out from the host of each request, which only works
    | for hosts of three or four labels. Prefer route_domain above. A request
    | for a two-label host (eg. yourdomain.com) is redirected to the subdomain.
    |
    */

    'route_subdomain' => env('LARAVEL_CRM_ROUTE_SUBDOMAIN', null),

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | This value is used to define whether you wish to use the crm in a
    | subfolder on your domain or just in the main directory. You can change
    | this value to anything you wish or simply set to blank.
    |
    | eg. https://yourdomain.com/crm or https://yourdomain.com
    |
    | Tip: You would use a subfolder if you are using this crm within a
    | current Laravel project that might be an entire application with routes
    | controllers, models, views, etc.
    |
    */

    'route_prefix' => env('LARAVEL_CRM_ROUTE_PREFIX', 'crm'),

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | For any custom middleware you have developed to be added to the crm routes
    |
    */

    'route_middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Database Table Prefix
    |--------------------------------------------------------------------------
    |
    | The crm tables will be prefixed with this value. It is optional but if you
    | are installing the crm into a current Laravel project it is best to leave
    | as the default "crm" to avoid any possible table name conflicts.
    |
    */

    'db_table_prefix' => env('LARAVEL_CRM_DB_TABLE_PREFIX', 'crm_'),

    /*
    |--------------------------------------------------------------------------
    | Database Table Field Encryption
    |--------------------------------------------------------------------------
    |
    | This is a security feature that will encrypt personal information in
    | certain database table fields as an added layer of privacy protection.
    |
    */

    'encrypt_db_fields' => env('LARAVEL_CRM_ENCRYPT_DB_FIELDS', false),

    /*
    |--------------------------------------------------------------------------
    | Front-end / User Interface
    |--------------------------------------------------------------------------
    |
    | The crm packages comes with a ready to go user interface. If you want to
    | build your own it is recommended to disable this to avoid any conflicts
    | or users coming across the default views. This works by not allowing
    | anything that hits the "laravel-crm" route prefix to load, which also
    | will override the route_prefix you set above.
    |
    */

    'user_interface' => env('LARAVEL_CRM_USER_INTERFACE', true),

    /*
    |--------------------------------------------------------------------------
    | Optional Modules
    |--------------------------------------------------------------------------
    |
    | Some of the features of the CRM package be disabled by removing them here
    | if they are not necessary for the business. An example of the deliveries
    | model which would not be useful if you sell digital only products or
    | services.
    |
    | Modules: "leads", "deals", "quotes", "orders", "invoices", "deliveries"
    |
    */

    'modules' => [
        'deals',
        'quotes',
        'orders',
        'invoices',
        'deliveries',
        'purchase-orders',
        'teams',
        'chat',
        'email-marketing',
        'sms-marketing',
        'features',
        'monitoring',
        'leads',
    ],

    /*
    |--------------------------------------------------------------------------
    | Order Form
    |--------------------------------------------------------------------------
    |
    | Trim the order create / edit form. Every key defaults to the full form,
    | so an install that sets nothing sees no change. These are install-wide,
    | including on teams installs.
    |
    | Hiding a field never changes stored data: an existing description,
    | labels, custom field values or address of an unlisted type survive an
    | edit and save untouched, and validation skips the hidden fields.
    |
    | `unallocated_owner` false removes "Unallocated" from the owner select,
    | presets an order with no owner to the current user, and makes the owner
    | required.
    |
    | `addresses` lists the address tabs to show and save, by address type
    | name ("billing", "shipping"). With one listed it is shown without tabs;
    | an empty array hides the addresses card.
    |
    | The quote, invoice and purchase-order forms are not covered yet; they
    | will take the same `form` shape under their own key.
    |
    */

    'orders' => [
        'form' => [
            'description' => true,
            'labels' => true,
            'custom_fields' => true,
            'unallocated_owner' => true,
            'addresses' => ['billing', 'shipping'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deliveries
    |--------------------------------------------------------------------------
    |
    | `delivered_on` false hides the "Delivered on" date from the delivery
    | form, show page, index and related index. The column, the API and any
    | stored value are left alone, and a save no longer writes it.
    |
    | `delivery_expected_label` is a key in the laravel-crm::lang file used to
    | label the expected delivery date on the form, show page, index and
    | related index, and on the delivery PDFs. For example "delivery_date"
    | prints "Delivery date". A key that does not exist falls back to
    | "delivery_expected". Left at "delivery_expected", the PDFs keep their
    | existing "Delivery date" heading.
    |
    */

    'deliveries' => [
        'delivered_on' => true,
        'delivery_expected_label' => 'delivery_expected',
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    |
    | Defaults for the uptime / SSL monitoring module. These values are used
    | by the MonitorService when creating monitors without explicit values and
    | by the MonitorCheckService / RunMonitorCheck job when scheduling and
    | evaluating checks.
    |
    */

    'monitoring' => [
        'default_frequency_minutes' => env('LARAVEL_CRM_MONITORING_DEFAULT_FREQUENCY_MINUTES', 5),
        'default_ssl_days_before_expiry_alert' => env('LARAVEL_CRM_MONITORING_DEFAULT_SSL_DAYS_BEFORE_EXPIRY_ALERT', 14),
        'request_timeout_seconds' => env('LARAVEL_CRM_MONITORING_REQUEST_TIMEOUT_SECONDS', 15),
        'ssl_recheck_hours' => env('LARAVEL_CRM_MONITORING_SSL_RECHECK_HOURS', 12),
        'max_response_bytes' => env('LARAVEL_CRM_MONITORING_MAX_RESPONSE_BYTES', 5 * 1024 * 1024),

        // Set true to allow monitors to target loopback / private / reserved
        // addresses. Off by default to mitigate SSRF from any admin who can
        // create monitors.
        'allow_private_targets' => env('LARAVEL_CRM_MONITORING_ALLOW_PRIVATE_TARGETS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Portal
    |--------------------------------------------------------------------------
    |
    | Settings for the public-facing portal (feature board, signed quote /
    | invoice / purchase-order links).
    |
    | `team_id` is optional. Under multi-tenant `teams` mode every team has
    | its own public board at /p/features/team/{id}, and the bare /p/features
    | resolves the board from the URL, the session, the signed-in user's
    | current team, or — on an install where only one team has a board — that
    | team. Set `team_id` only to pin the portal to a single team and 404
    | everything outside it. When teams mode is off it is ignored.
    |
    | `allow_registration` opts the host application into letting anonymous
    | visitors create user accounts via /p/register. Off by default: enabling
    | it writes rows to the host app's users table and dispatches Laravel's
    | Registered event for each signup.
    |
    */

    'portal' => [
        'allow_registration' => env('LARAVEL_CRM_PORTAL_ALLOW_REGISTRATION', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    |
    | Settings for the REST API V2. `token_attempts_per_account` and
    | `token_attempts_decay_seconds` together throttle the POST /api/crm/v2
    | /auth/token endpoint per email address, on top of the IP-keyed
    | throttle:6,1 limiter. Defaults to 5 failed attempts per 10 minutes.
    |
    */

    'api' => [
        'token_attempts_per_account' => env('LARAVEL_CRM_API_TOKEN_ATTEMPTS_PER_ACCOUNT', 5),
        'token_attempts_decay_seconds' => env('LARAVEL_CRM_API_TOKEN_ATTEMPTS_DECAY_SECONDS', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Settings for the public feature board. `view_dedup_minutes` controls how
    | long a single visitor (matched by authenticated user, or hashed IP for
    | anonymous viewers) is treated as the same view before another GET to the
    | public feature page records an additional FeatureView row. Set to 0 to
    | record every request.
    |
    */

    'features' => [
        'view_dedup_minutes' => env('LARAVEL_CRM_FEATURES_VIEW_DEDUP_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Update Notifications
    |--------------------------------------------------------------------------
    |
    | Update this to false if you don't want to show any users that there are
    | package updates available.
    |
    */

    'update_notifications' => env('LARAVEL_CRM_UPDATE_NOTIFICATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Documentation URL
    |--------------------------------------------------------------------------
    |
    | Where the system check banner points users for release details. Override
    | this if you host your own documentation.
    |
    */

    'docs_url' => env('LARAVEL_CRM_DOCS_URL', 'https://github.com/IvanMamaniPerez/back-qaturia'),

    /*
    |--------------------------------------------------------------------------
    | Upgrade guide URL
    |--------------------------------------------------------------------------
    |
    | Where every "upgrade guide" link points — the updates page and the system
    | check banner. Separate from docs_url because that one answers "what is in
    | this release?" and this one answers "how do I install it?".
    |
    */

    'upgrade_guide_url' => env('LARAVEL_CRM_UPGRADE_GUIDE_URL', 'https://github.com/IvanMamaniPerez/back-qaturia'),

    /*
    |--------------------------------------------------------------------------
    | Models with Global
    |--------------------------------------------------------------------------
    |
    | With multi-tenant support, you can have model rows that are global, using
    | a "global" column in the table. eg. settings
    |
    |
    */

    'model_with_global' => [
        'settings',
    ],

];
