import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:adbana_carta/carta_api.dart';
import 'package:adbana_carta/restaurantes_page.dart';

void main() {
  test('El catálogo usa la API y conserva paginación', () async {
    final api = CartaApi(
      baseUrl: 'https://example.test',
      client: MockClient((request) async {
        expect(request.url.path, '/api/restaurantes');
        expect(request.url.queryParameters['page'], '2');
        return http.Response(jsonEncode({'data': [], 'last_page': 2}), 200);
      }),
    );
    expect((await api.request('restaurantes?page=2'))['last_page'], 2);
    api.close();
    expect(const RestaurantesPage(), isNotNull);
  });
  test('El pedido conserva clave y restaurante en cada reintento', () async {
    final bodies = <String>[];
    final api = CartaApi(
      baseUrl: 'https://example.test',
      client: MockClient((request) async {
        expect(request.url.path, '/api/carta/yurakuna/pedidos');
        bodies.add(request.body);
        return http.Response('{"pedido_id":12}', 201);
      }),
    );
    final body = {
      'clave_operacion': 'misma-clave',
      'items': [
        {'producto_id': 1, 'cantidad': 2},
      ],
    };
    await api.request('carta/yurakuna/pedidos', body: body);
    await api.request('carta/yurakuna/pedidos', body: body);
    expect(bodies[0], bodies[1]);
    api.close();
  });
  test('Los errores de validación se muestran sin simular éxito', () async {
    final api = CartaApi(
      client: MockClient(
        (_) async => http.Response(
          '{"errors":{"tipo":["Modalidad no disponible"]}}',
          422,
        ),
      ),
    );
    await expectLater(
      api.request('carta/a/pedidos', body: {}),
      throwsA(
        isA<CartaApiException>()
            .having((e) => e.status, 'estado', 422)
            .having((e) => e.message, 'mensaje', 'Modalidad no disponible'),
      ),
    );
    api.close();
  });
}
