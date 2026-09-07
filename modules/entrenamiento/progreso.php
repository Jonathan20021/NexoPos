<?php
/**
 * Acciones sobre el avance propio (POST). Patrón PRG: procesa y redirige.
 *
 * Aquí solo se toca el avance de UNO MISMO. Reiniciarle el entrenamiento a otra
 * persona es supervisión y vive en `equipo.php`, con su permiso.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

if (!isPost()) {
    redirect('modules/entrenamiento/index.php');
}
verify_csrf();

$accion = post('accion');
$rk     = (string) post('ruta');
$lk     = (string) post('leccion');

if ($accion === 'completar') {
    $info = ent_leccion($rk, $lk);
    if (!$info) {
        flash('error', 'Esa lección no está disponible para tu rol.');
        redirect('modules/entrenamiento/index.php');
    }

    // El tiempo lo manda la pantalla, pero no se cree a ciegas: `ent_completar()`
    // lo acota. Es una estimación de dedicación, no un control horario.
    ent_completar($rk, $lk, (int) post('segundos', 0));

    // Encadenar es lo que sostiene el hábito: al terminar una lección, la
    // siguiente ya está abierta. Solo se para al acabar la ruta.
    if ($info['siguiente']) {
        flash('success', 'Lección completada. Vamos con la siguiente.');
        redirect('modules/entrenamiento/leccion.php?ruta=' . rawurlencode($rk)
                 . '&leccion=' . rawurlencode($info['siguiente']));
    }

    $a = ent_avance_ruta($info['ruta'], ent_progreso(), $rk);
    if ($a['completadas'] >= $a['total'] && ent_quiz_ruta($rk)) {
        flash('success', 'Terminaste «' . $info['ruta']['titulo'] . '». Ya puedes presentar la evaluación.');
    } else {
        flash('success', 'Lección completada.');
    }
    redirect('modules/entrenamiento/ruta.php?ruta=' . rawurlencode($rk));
}

if ($accion === 'reiniciar_ruta') {
    if (!ent_ruta($rk)) {
        flash('error', 'Esa ruta no está disponible.');
        redirect('modules/entrenamiento/index.php');
    }
    ent_reiniciar((int) current_user()['id'], $rk);
    flash('info', 'Se reinició tu avance en esa ruta.');
    redirect('modules/entrenamiento/ruta.php?ruta=' . rawurlencode($rk));
}

if ($accion === 'reiniciar_todo') {
    ent_reiniciar((int) current_user()['id']);
    flash('info', 'Se reinició todo tu entrenamiento.');
    redirect('modules/entrenamiento/index.php');
}

redirect('modules/entrenamiento/index.php');
