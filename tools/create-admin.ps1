param(
    [string]$FullName,
    [string]$Email,
    [string]$PhpPath = 'D:\xamp\php\php.exe'
)

$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $PhpPath)) { throw 'PHP was not found. Supply its path using -PhpPath.' }
if (-not $FullName) { $FullName = Read-Host 'Administrator full name' }
if (-not $Email) { $Email = Read-Host 'Administrator email' }
$adminPassword = Read-Host 'Password (at least 8 characters, at most 72 UTF-8 bytes)' -AsSecureString
$adminConfirmation = Read-Host 'Confirm password' -AsSecureString
$passwordPointer = [IntPtr]::Zero
$confirmationPointer = [IntPtr]::Zero

try {
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($adminPassword)
    $confirmationPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($adminConfirmation)
    $adminRequest = @{
        full_name = $FullName
        email = $Email
        password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
        password_confirmation = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($confirmationPointer)
    }
    $OutputEncoding = [System.Text.UTF8Encoding]::new($false)
    $adminRequest | ConvertTo-Json -Compress | & $PhpPath (Join-Path $PSScriptRoot 'create-admin.php')
    $adminExitCode = $LASTEXITCODE
} finally {
    if ($passwordPointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer) }
    if ($confirmationPointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($confirmationPointer) }
    if ($adminRequest) { $adminRequest.Clear() }
    $adminPassword.Dispose()
    $adminConfirmation.Dispose()
}
exit $adminExitCode
