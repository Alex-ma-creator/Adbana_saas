// Modelo externo local: ver models/CREDITOS.md.
AFRAME.registerComponent('plato-demo', {
  init() {
    const model = document.createElement('a-entity');
    model.setAttribute('gltf-model', 'url(models/pizza.glb)');
    model.addEventListener('model-loaded', () => {
      const mesh = model.getObject3D('mesh');
      const box = new THREE.Box3().setFromObject(mesh);
      const size = box.getSize(new THREE.Vector3());
      const center = box.getCenter(new THREE.Vector3());
      const scale = 2.2 / Math.max(size.x, size.z);
      model.setAttribute('scale', [scale, scale, scale].join(' '));
      model.setAttribute('position', [-center.x * scale, -box.min.y * scale, -center.z * scale].join(' '));
      const status = document.querySelector('#model-status');
      if (status) status.textContent = 'Pizza 3D lista';
    });
    model.addEventListener('model-error', () => {
      const status = document.querySelector('#model-status') || document.querySelector('#status');
      if (status) status.textContent = 'No se pudo cargar el modelo. Recarga la página.';
    });
    this.el.appendChild(model);
  }
});
