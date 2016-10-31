docker run \
    -e "OPINE_ENV=docker" \
    --rm \
    -v "$(pwd)/../":/app opine:phpunit-container \
    --bootstrap /app/tests/bootstrap.php
