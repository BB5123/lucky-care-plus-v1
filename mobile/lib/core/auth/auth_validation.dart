/// Client-side validation that mirrors `AuthController` on the server.
///
/// The backend stays authoritative (it re-validates every field), but blocking
/// an obviously invalid form avoids a pointless round trip and a 422.
abstract final class AuthValidator {
  static const int minPasswordLength = 10;

  static final RegExp _emailShape = RegExp(
    r'^[^@\s]+@[^@\s]+\.[^@\s]+$',
  );

  static bool isValidEmail(String value) {
    return _emailShape.hasMatch(value.trim());
  }

  static bool isValidPassword(String value) {
    return value.length >= minPasswordLength;
  }

  static bool passwordsMatch(String password, String confirmation) {
    return password == confirmation;
  }
}
