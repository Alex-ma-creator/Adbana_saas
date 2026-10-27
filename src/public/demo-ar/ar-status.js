const statusText=document.querySelector('#status');
const show=key=>DemoI18n.status(statusText,key);
document.querySelector('a-marker').addEventListener('markerFound',()=>show('markerFound'));
document.querySelector('a-marker').addEventListener('markerLost',()=>show('markerLost'));
window.addEventListener('camera-init',()=>show('cameraReady'));
window.addEventListener('camera-error',()=>show('cameraError'));
