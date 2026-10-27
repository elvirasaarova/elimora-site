/* Track only the public handoff, without project contents or client details. */
export function openProjectCabinet() {
  const destination = 'account?new=1';
  if (!['elimora.ru', 'www.elimora.ru'].includes(location.hostname) || typeof window.ym !== 'function') {
    location.href = destination;
    return;
  }
  let finished = false;
  let timer;
  const proceed = () => {
    if (finished) return;
    finished = true;
    clearTimeout(timer);
    location.href = destination;
  };
  timer = setTimeout(proceed, 500);
  try {
    window.ym(113823074, 'reachGoal', 'project_handoff', {}, proceed);
  } catch (_) {
    proceed();
  }
}
