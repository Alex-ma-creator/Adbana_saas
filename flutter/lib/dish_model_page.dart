import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:model_viewer_plus/model_viewer_plus.dart';

import 'menu_data.dart';
import 'palette.dart';

class DishModelPage extends StatelessWidget {
  const DishModelPage({super.key, required this.dish});
  final Dish dish;

  @override
  Widget build(BuildContext context) {
    final asset = dish.modelAsset;
    return Scaffold(
      backgroundColor: paper,
      appBar: AppBar(title: Text(dish.name)),
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child: asset == null
                  ? const Center(
                      child: Text('Este plato aún no tiene modelo 3D.'),
                    )
                  : ModelViewer(
                      src: Uri.tryParse(asset)?.hasScheme == true
                          ? asset
                          : (kIsWeb ? 'assets/$asset' : asset),
                      alt: 'Modelo ilustrativo de ${dish.name}',
                      backgroundColor: paper,
                      ar: true,
                      arModes: const ['webxr', 'scene-viewer', 'quick-look'],
                      cameraControls: true,
                      autoRotate: false,
                      shadowIntensity: 1,
                      debugLogging: false,
                      innerModelViewerHtml: '''
                        <button slot="ar-button" style="position:absolute;bottom:24px;left:50%;transform:translateX(-50%);padding:14px 22px;border:0;border-radius:24px;background:#e54635;color:white;font:600 15px sans-serif;white-space:nowrap">Ver en mi espacio</button>
                        <div id="model-error" role="alert" style="display:none;position:absolute;inset:25%;background:#faf9f6;padding:20px;font:15px sans-serif">No se pudo cargar el modelo. Vuelve a la foto e inténtalo nuevamente.</div>
                      ''',
                      relatedJs: '''
                        const viewer = document.querySelector('model-viewer');
                        viewer.addEventListener('error', () => document.getElementById('model-error').style.display = 'block');
                      ''',
                    ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 12, 24, 24),
              child: Column(
                children: [
                  const Text(
                    'Arrastra para girar. Amplía con dos dedos o la rueda del mouse.',
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 10),
                  const Text(
                    'Realidad aumentada disponible según el dispositivo. Si aparece «Ver en mi espacio», púlsalo y sigue las instrucciones. En navegador necesita HTTPS o localhost.',
                    style: TextStyle(color: muted, fontSize: 12, height: 1.5),
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 8),
                  Text(
                    asset?.startsWith('assets/') == true
                        ? 'Modelo de demostración de Innerscene · CC0. La presentación y el tamaño real pueden variar.'
                        : 'Modelo facilitado por el restaurante. La presentación y el tamaño real pueden variar.',
                    style: TextStyle(color: muted, fontSize: 11),
                    textAlign: TextAlign.center,
                  ),
                  TextButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text('Volver a la foto del plato'),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
