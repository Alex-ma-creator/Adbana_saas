// Interacción solo en el visor 3D. La AR conserva el seguimiento del marcador.
const scene = document.querySelector('a-scene');
const dish = document.querySelector('[plato-demo]');
let yaw = 0, tilt = 0, pointer = null, lastX = 0, lastY = 0;
function rotate(dx, dy) {
  yaw += dx * 0.45;
  tilt = Math.max(-20, Math.min(40, tilt + dy * 0.3));
  dish.setAttribute('rotation', [tilt, yaw, 0].join(' '));
}
function setup() {
  const canvas = scene.canvas;
  canvas.style.touchAction = 'none';
  canvas.style.cursor = 'grab';
  canvas.addEventListener('pointerdown', event => {
    if (pointer !== null || (event.pointerType === 'mouse' && event.button !== 0)) return;
    pointer = event.pointerId; lastX = event.clientX; lastY = event.clientY;
    canvas.setPointerCapture(pointer); canvas.style.cursor = 'grabbing';
    scene.focus({preventScroll: true});
  });
  canvas.addEventListener('pointermove', event => {
    if (event.pointerId !== pointer) return;
    rotate(event.clientX - lastX, event.clientY - lastY);
    lastX = event.clientX; lastY = event.clientY;
  });
  function release(event) {
    if (event.pointerId !== pointer) return;
    if (canvas.hasPointerCapture(pointer)) canvas.releasePointerCapture(pointer);
    pointer = null; canvas.style.cursor = 'grab';
  }
  for (const name of ['pointerup', 'pointercancel', 'lostpointercapture']) canvas.addEventListener(name, release);
}
if (scene.canvas) setup(); else scene.addEventListener('render-target-loaded', setup, {once:true});
scene.addEventListener('keydown', event => {
  const steps = {ArrowLeft:[-30,0], ArrowRight:[30,0], ArrowUp:[0,-20], ArrowDown:[0,20]};
  if (!steps[event.key]) return;
  event.preventDefault(); rotate(...steps[event.key]);
});
