import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:model_viewer_plus/model_viewer_plus.dart';

import 'menu_data.dart';
import 'palette.dart';

class DishModelPage extends StatelessWidget {
  const DishModelPage({super.key, required this.dish});
  final Dish dish;

  String _resolveSrc(String asset) {
    return Uri.tryParse(asset)?.hasScheme == true
        ? asset
        : (kIsWeb ? 'assets/$asset' : asset);
  }

  String _resolveIosSrc(String asset) {
    final resolved = _resolveSrc(asset);
    final base = resolved.split('?').first.replaceAll(RegExp(r'\.glb$', caseSensitive: false), '.usdz');
    return '$base#allowsContentScaling=0';
  }

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
                  // Limitación documentada: model_viewer_plus ejecuta un WebView embebido
                  // que no expone XRAnchor de WebXR directamente en Flutter. Por ello se prioriza
                  // 'scene-viewer' (ARCore nativo en Android) y 'quick-look' (ARKit con .usdz en iOS).
                  // Si ARCore/ARKit no está instalado o falla, se oculta el botón AR y queda el visor 3D.
                  : ModelViewer(
                      src: _resolveSrc(asset),
                      iosSrc: _resolveIosSrc(asset),
                      alt: 'Modelo ilustrativo de ${dish.name}',
                      backgroundColor: paper,
                      ar: true,
                      arModes: const ['webxr', 'scene-viewer', 'quick-look'],
                      arPlacement: ArPlacement.floor,
                      arScale: ArScale.fixed,
                      xrEnvironment: true,
                      cameraControls: true,
                      cameraOrbit: '0deg 65deg 105%',
                      minCameraOrbit: 'auto 15deg 20%',
                      maxCameraOrbit: 'auto 88deg 300%',
                      autoRotate: false,
                      shadowIntensity: 1.5,
                      shadowSoftness: 0.75,
                      exposure: 1.1,
                      debugLogging: false,
                      innerModelViewerHtml: '''
                        <button id="ar-btn-mesa" slot="ar-button" style="min-height:48px;position:absolute;bottom:24px;left:50%;transform:translateX(-50%);padding:12px 22px;border:2px solid #fff;border-radius:24px;background:#c2410c;color:white;font:700 15px sans-serif;white-space:nowrap;box-shadow:0 6px 18px rgba(0,0,0,0.45)">Ver en mi espacio</button>
                        <div id="ar-scan-banner" role="status" style="display:none;position:fixed;top:64px;left:50%;transform:translateX(-50%);width:88%;max-width:390px;background:rgba(12,10,9,0.94);color:#fff;border:2px solid rgba(255,145,77,0.9);padding:12px 16px;border-radius:16px;font:600 13px/1.45 sans-serif;text-align:center;z-index:30;pointer-events:none;box-shadow:0 10px 28px rgba(0,0,0,0.72)">📲 Apunta al borde o centro de la mesa e inclina ligeramente el celular para fijar el plato</div>
                        <div id="ar-tracking-warn" role="alert" style="display:none;flex-direction:column;align-items:center;gap:10px;position:fixed;top:68px;left:50%;transform:translateX(-50%);width:90%;max-width:400px;background:rgba(28,15,10,0.96);color:#fef3c7;border:2px solid #f59e0b;border-radius:16px;padding:14px 16px;font:600 13px/1.45 sans-serif;text-align:center;z-index:35;box-shadow:0 12px 30px rgba(0,0,0,0.8)">
                          <span>⚠️ Muy cerca de la superficie — aléjate unos centímetros mirando a la mesa para recuperar el plato</span>
                          <button id="ar-recenter-btn" type="button" style="min-height:44px;padding:9px 16px;border-radius:22px;border:1.5px solid #fff;background:#d97706;color:#fff;font:700 13px sans-serif;cursor:pointer">🔄 Re-centrar plato en mesa</button>
                        </div>
                        <div id="ar-lock-shield" class="ar-lock-shield" style="display:none;position:fixed;top:76px;bottom:96px;left:0;right:0;z-index:15;pointer-events:none;touch-action:auto;background:transparent"></div>
                        <div id="ar-lock-wrap" style="display:none;justify-content:center;position:fixed;bottom:24px;left:50%;transform:translateX(-50%);width:92%;max-width:410px;z-index:30">
                          <button id="ar-lock-btn" type="button" style="min-height:50px;width:100%;padding:12px 18px;border-radius:25px;border:2px solid #fff;background:#15803d;color:#fff;font:700 13.5px/1.3 sans-serif;box-shadow:0 8px 22px rgba(0,0,0,0.75);cursor:pointer">🔒 Plato fijo en mesa (Toca aquí para recolocar)</button>
                        </div>
                        <div id="ar-unsupported-msg" role="status" style="display:none;position:absolute;bottom:20px;left:50%;transform:translateX(-50%);width:88%;max-width:360px;background:rgba(12,10,9,0.88);color:#fde68a;padding:10px 14px;border-radius:12px;font:12px/1.4 sans-serif;text-align:center;pointer-events:none">Tu celular no soporta realidad aumentada; puedes ver el plato en 3D.</div>
                        <div id="model-error" role="alert" style="display:none;position:absolute;inset:25%;background:#faf9f6;padding:20px;font:15px sans-serif">No se pudo cargar el modelo. Vuelve a la foto e inténtalo nuevamente.</div>
                      ''',
                      relatedJs: '''
                        const viewer = document.querySelector('model-viewer');
                        const arBtn = document.getElementById('ar-btn-mesa');
                        const scanBanner = document.getElementById('ar-scan-banner');
                        const trackingWarn = document.getElementById('ar-tracking-warn');
                        const recenterBtn = document.getElementById('ar-recenter-btn');
                        const lockShield = document.getElementById('ar-lock-shield');
                        const lockWrap = document.getElementById('ar-lock-wrap');
                        const lockBtn = document.getElementById('ar-lock-btn');
                        const unsupportedMsg = document.getElementById('ar-unsupported-msg');

                        let isLocked = false;
                        let lockTimer = null;

                        const patchNearPlane = () => {
                          if (!viewer || typeof Object.getOwnPropertySymbols !== 'function') return;
                          try {
                            for (const sym of Object.getOwnPropertySymbols(viewer)) {
                              const val = viewer[sym];
                              if (!val || typeof val !== 'object') continue;
                              if (val.camera && typeof val.camera.updateProjectionMatrix === 'function') {
                                val.camera.near = 0.01;
                                val.camera.updateProjectionMatrix();
                              }
                              const renderers = [val.threeRenderer, val.renderer && val.renderer.threeRenderer, val.arRenderer && val.arRenderer.threeRenderer, val];
                              for (const r of renderers) {
                                if (!r || !r.xr) continue;
                                if (typeof r.xr.getSession === 'function') {
                                  const session = r.xr.getSession();
                                  if (session && typeof session.updateRenderState === 'function') {
                                    session.updateRenderState({ depthNear: 0.01, depthFar: 20.0 });
                                  }
                                }
                              }
                            }
                          } catch (e) {}
                        };

                        const applyLockState = (locked) => {
                          isLocked = locked;
                          if (lockShield) {
                            lockShield.style.pointerEvents = locked ? 'auto' : 'none';
                            lockShield.style.touchAction = locked ? 'none' : 'auto';
                          }
                          if (lockBtn) {
                            lockBtn.style.background = locked ? '#15803d' : '#c2410c';
                            lockBtn.textContent = locked
                              ? '🔒 Plato fijo en mesa (Toca aquí para recolocar)'
                              : '🎯 Modo recolocar activo (Toca la mesa para mover el plato y luego fija) · 📌 Fijar plato aquí';
                          }
                        };

                        for (const el of [scanBanner, trackingWarn, recenterBtn, lockShield, lockWrap, lockBtn]) {
                          if (!el) continue;
                          el.addEventListener('beforexrselect', (e) => e.preventDefault());
                        }
                        if (lockShield) {
                          lockShield.addEventListener('pointerdown', (e) => { if (isLocked) e.stopPropagation(); });
                          lockShield.addEventListener('touchstart', (e) => { if (isLocked) e.stopPropagation(); });
                        }
                        if (lockBtn) {
                          lockBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (lockTimer) clearTimeout(lockTimer);
                            applyLockState(!isLocked);
                            patchNearPlane();
                          });
                        }
                        if (recenterBtn) {
                          recenterBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (lockTimer) clearTimeout(lockTimer);
                            if (trackingWarn) trackingWarn.style.display = 'none';
                            applyLockState(false);
                            patchNearPlane();
                          });
                        }

                        const ocultarARSiNoDisponible = () => {
                          if (arBtn) arBtn.style.display = 'none';
                          if (unsupportedMsg) unsupportedMsg.style.display = 'block';
                        };

                        viewer.addEventListener('error', () => document.getElementById('model-error').style.display = 'block');
                        viewer.addEventListener('ar-status', (ev) => {
                          const st = ev && ev.detail ? ev.detail.status : '';
                          if (st === 'failed') {
                            ocultarARSiNoDisponible();
                          } else if (st === 'session-started') {
                            if (lockTimer) clearTimeout(lockTimer);
                            if (scanBanner) scanBanner.style.display = 'block';
                            if (trackingWarn) trackingWarn.style.display = 'none';
                            if (lockShield) lockShield.style.display = 'none';
                            if (lockWrap) lockWrap.style.display = 'none';
                            applyLockState(false);
                            patchNearPlane();
                          } else if (st === 'object-placed') {
                            if (scanBanner) scanBanner.style.display = 'none';
                            if (trackingWarn) trackingWarn.style.display = 'none';
                            if (lockShield) lockShield.style.display = 'block';
                            if (lockWrap) lockWrap.style.display = 'flex';
                            patchNearPlane();
                            if (lockTimer) clearTimeout(lockTimer);
                            lockTimer = setTimeout(() => {
                              applyLockState(true);
                              patchNearPlane();
                            }, 400);
                          } else if (st === 'not-presenting') {
                            if (lockTimer) clearTimeout(lockTimer);
                            if (scanBanner) scanBanner.style.display = 'none';
                            if (trackingWarn) trackingWarn.style.display = 'none';
                            if (lockShield) lockShield.style.display = 'none';
                            if (lockWrap) lockWrap.style.display = 'none';
                            applyLockState(false);
                          }
                        });

                        viewer.addEventListener('ar-tracking', (ev) => {
                          const tr = ev && ev.detail ? ev.detail.status : '';
                          if (tr === 'not-tracking') {
                            if (trackingWarn) trackingWarn.style.display = 'flex';
                            patchNearPlane();
                          } else if (tr === 'tracking') {
                            if (trackingWarn) trackingWarn.style.display = 'none';
                            patchNearPlane();
                          }
                        });

                        viewer.addEventListener('load', () => {
                          setTimeout(() => {
                            if (viewer.canActivateAR === false) {
                              ocultarARSiNoDisponible();
                            }
                          }, 600);
                          if (typeof viewer.getDimensions !== 'function') return;
                          const dim = viewer.getDimensions();
                          if (!dim) return;
                          const maxHoriz = Math.max(dim.x || 0, dim.z || 0);
                          if (maxHoriz <= 0) return;
                          const altText = (viewer.getAttribute('alt') || '').toLowerCase();
                          let targetMeters = 0.25;
                          if (altText.includes('pizza')) targetMeters = 0.35;
                          else if (altText.includes('hamburguesa') || altText.includes('burger')) targetMeters = 0.14;
                          if (maxHoriz < 0.08 || maxHoriz > 0.45 || Math.abs(maxHoriz - targetMeters) > 0.04) {
                            const s = (targetMeters / maxHoriz).toFixed(4);
                            viewer.scale = s + ' ' + s + ' ' + s;
                            if (typeof viewer.updateFraming === 'function') viewer.updateFraming();
                          }
                        });
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
                    'Realidad aumentada con anclaje en superficie: apunta a tu mesa (idealmente con algún objeto o textura cerca si la mesa es muy lisa o de vidrio) para fijar el plato en tamaño real.',
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
