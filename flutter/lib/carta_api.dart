import 'dart:convert';
import 'dart:math';

import 'package:http/http.dart' as http;

class CartaApi {
  CartaApi({http.Client? client, String? baseUrl})
    : client = client ?? http.Client(),
      baseUrl =
          baseUrl ??
          const String.fromEnvironment(
            'API_URL',
            defaultValue: 'http://localhost:8080',
          );

  static final String clientId = newOperationKey();
  final http.Client client;
  final String baseUrl;

  Future<Map<String, dynamic>> request(
    String path, {
    Map<String, dynamic>? body,
  }) async {
    final uri = Uri.parse('$baseUrl/api/$path');
    final headers = {
      'X-Adbana-Cliente': clientId,
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    };
    final response =
        await (body == null
                ? client.get(uri, headers: headers)
                : client.post(uri, headers: headers, body: jsonEncode(body)))
            .timeout(const Duration(seconds: 20));
    final data = jsonDecode(response.body) as Map<String, dynamic>;
    if (response.statusCode >= 400) {
      final errors = data['errors'] as Map<String, dynamic>?;
      throw CartaApiException(
        response.statusCode,
        errors == null
            ? (data['message'] ?? 'No se pudo completar la solicitud.')
            : errors.values.expand((e) => e as List).join('\n'),
      );
    }
    return data;
  }

  void close() => client.close();
}

class CartaApiException implements Exception {
  const CartaApiException(this.status, this.message);
  final int status;
  final String message;
  @override
  String toString() => message;
}

String newOperationKey() {
  final random = Random.secure();
  final bytes = List<int>.generate(16, (_) => random.nextInt(256));
  bytes[6] = (bytes[6] & 15) | 64;
  bytes[8] = (bytes[8] & 63) | 128;
  final h = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-${h.substring(16, 20)}-${h.substring(20)}';
}

int priceCents(Object value) {
  final parts = value.toString().split('.');
  return int.parse(parts[0]) * 100 +
      int.parse(
        (parts.length == 1 ? '0' : parts[1]).padRight(2, '0').substring(0, 2),
      );
}
