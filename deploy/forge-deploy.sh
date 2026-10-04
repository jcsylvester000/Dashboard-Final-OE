# Laravel Forge deploy script for OverEasy Dashboard (paste into Site > Deployments > Deploy Script).
# Zero-downtime: enable "Zero downtime deployments" on the Forge site; Forge supplies $FORGE_* vars.
# Never put secrets here - they live in the site's Environment (.env) in Forge.
set -e

cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

npm ci
npm run build

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

if [ -f artisan ]; then
    $FORGE_PHP artisan migrate --force
    # Roles/permissions/departments are idempotent: new permissions appear, edits are kept.
    $FORGE_PHP artisan db:seed --class=RolesAndPermissionsSeeder --force
    $FORGE_PHP artisan storage:link || true
    $FORGE_PHP artisan optimize
    $FORGE_PHP artisan queue:restart
    # Only if Reverb is installed and its Forge daemon is set up:
    # $FORGE_PHP artisan reverb:restart
fi
