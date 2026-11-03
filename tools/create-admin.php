<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../auth/auth.php';

try {
    // Passwords arrive through standard input, never command-line arguments.
    $request = stream_get_contents(STDIN, 32769);
    if (strlen($request) > 32768) {
        throw new InvalidArgumentException('The account input is too long.');
    }
    $input = json_decode($request, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Provide an account name, email, and password.');
    }
    $validation = validateRegistrationInput($input);
    if ($validation['errors'] !== []) {
        throw new InvalidArgumentException(implode(' ', $validation['errors']));
    }
    if (findUserByEmail($validation['values']['email']) !== null) {
        throw new InvalidArgumentException('An account with this email already exists. Choose a different email.');
    }
    createUser($validation['values'], $validation['password'], 'admin');
    fwrite(STDOUT, 'Administrator created: ' . $validation['values']['email'] . "\n");
} catch (PDOException $error) {
    error_log('Administrator setup database error: ' . $error->getMessage());
    fwrite(STDERR, "Administrator setup failed. Check the database configuration or whether the email is already registered.\n");
    exit(1);
} catch (InvalidArgumentException | JsonException $error) {
    fwrite(STDERR, 'Administrator setup: ' . $error->getMessage() . "\n");
    exit(1);
} catch (Throwable $error) {
    error_log('Administrator setup error: ' . $error->getMessage());
    fwrite(STDERR, "Administrator setup could not be completed. Please try again.\n");
    exit(1);
}
