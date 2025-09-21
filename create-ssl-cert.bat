@echo off
REM Create SSL certificates for Hi.Events development

echo Creating SSL certificates for Hi.Events development...

REM Create certs directory if it doesn't exist
if not exist "docker\development\certs" mkdir docker\development\certs

REM Generate private key and certificate using Docker (since OpenSSL might not be installed on Windows)
docker run --rm -v "%cd%\docker\development\certs:/certs" alpine/openssl req -x509 -newkey rsa:4096 -keyout /certs/localhost.key -out /certs/localhost.crt -days 365 -nodes -subj "/C=US/ST=State/L=City/O=Organization/CN=localhost"

echo.
echo ✓ SSL certificates created successfully!
echo Files created:
echo   - docker\development\certs\localhost.crt
echo   - docker\development\certs\localhost.key
echo.
echo You can now start the nginx container for HTTPS support:
echo   docker-compose -f docker\development\docker-compose.dev.yml up nginx -d
echo.
pause
