param(
    [string]$PhpPath = 'D:\xamp\php\php.exe',
    [switch]$Browser
)

$ErrorActionPreference = 'Stop'
$previousLocation = Get-Location
$failedSteps = @()
$runnerExitCode = 0

try {
    $projectPath = Split-Path -Parent $PSScriptRoot
    Set-Location -LiteralPath $projectPath
    $phpExecutable = (Get-Command -Name $PhpPath -CommandType Application -ErrorAction Stop).Source
    $nodeExecutable = (Get-Command -Name 'node' -CommandType Application -ErrorAction Stop).Source

    $steps = @(
        @{ Name = 'Task 2: Database catalog'; Executable = $phpExecutable; Arguments = @('tests/task2-integration.php') },
        @{ Name = 'Task 3: Service CRUD'; Executable = $phpExecutable; Arguments = @('tests/task3-integration.php') },
        @{ Name = 'Task 4-5: Authentication and sessions'; Executable = $phpExecutable; Arguments = @('tests/task4-integration.php') },
        @{ Name = 'Task 6: Role-based access'; Executable = $phpExecutable; Arguments = @('tests/task6-integration.php') },
        @{ Name = 'Task 5: Theme cookie behavior'; Executable = $nodeExecutable; Arguments = @('--test', 'tests/task5-theme.test.cjs') }
    )
    if ($Browser) {
        $steps += @{ Name = 'Browser: Theme and cookie persistence'; Executable = $nodeExecutable; Arguments = @('tools/verify-task5-browser.mjs') }
    }

    Write-Host 'Lab 2 verification runs sequentially; temporary test accounts and services are cleaned up by each test.'
    Write-Host 'Start Apache and MySQL before running this command.'
    foreach ($step in $steps) {
        Write-Host "`nRunning $($step.Name)..."
        try {
            $executable = $step.Executable
            $arguments = $step.Arguments
            & $executable @arguments
            if ($LASTEXITCODE -ne 0) {
                throw "Test process exited with code $LASTEXITCODE."
            }
            Write-Host "PASS: $($step.Name)"
        } catch {
            $failedSteps += $step.Name
            Write-Host "FAIL: $($step.Name) - $($_.Exception.Message)"
        }
    }

    if ($failedSteps.Count -gt 0) {
        $runnerExitCode = 1
        Write-Host "`nFailed $($failedSteps.Count) of $($steps.Count) steps: $($failedSteps -join '; ')"
    } else {
        Write-Host "`nPASS: All $($steps.Count) verification steps completed."
    }
} catch {
    $runnerExitCode = 1
    Write-Host "Verification could not start: $($_.Exception.Message)"
} finally {
    Set-Location -LiteralPath $previousLocation.Path
}

exit $runnerExitCode
