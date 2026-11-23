<?php
$paginaActual = 'clases';
include __DIR__ . '/includes/header.php';
?>

<main class="pagina-clases">
    <section class="encabezado-clases" aria-labelledby="titulo-clases">
        <p class="etiqueta-clases"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i> Team Cats · Jiu-Jitsu</p>
        <h1 id="titulo-clases">Tu próxima clase empieza en el tatami</h1>
        <p>Entrena con constancia, aprende a tu ritmo y forma parte de nuestra comunidad.</p>
    </section>

    <section class="horario-semanal" aria-labelledby="titulo-horario">
        <div class="cabecera-horario">
            <div>
                <p>HORARIO SEMANAL</p>
                <h2 id="titulo-horario">Encuentra tu momento para entrenar</h2>
            </div>
            <span><i class="fa-regular fa-clock" aria-hidden="true"></i> Matamoros, Tamaulipas</span>
        </div>

        <div class="dias-horario">
            <article class="dia-horario dia-con-clase">
                <h3>Lunes</h3>
                <div class="sesion-horario">
                    <span class="tipo-sesion">CLASE GRUPAL</span>
                    <h4>Jiu-Jitsu</h4>
                    <p class="detalle-sesion">Entrenamiento en equipo</p>
                    <p class="hora-sesion"><i class="fa-regular fa-clock" aria-hidden="true"></i> <time datetime="19:00">7:00 p. m.</time><span>–</span><time datetime="21:30">9:30 p. m.</time></p>
                </div>
            </article>
            <article class="dia-horario dia-sin-clase">
                <h3>Martes</h3>
                <p>Dia sin clase</p>
            </article>
            <article class="dia-horario dia-con-clase">
                <h3>Miércoles</h3>
                <div class="sesion-horario">
                    <span class="tipo-sesion">CLASE GRUPAL</span>
                    <h4>Jiu-Jitsu</h4>
                    <p class="detalle-sesion">Entrenamiento en equipo</p>
                    <p class="hora-sesion"><i class="fa-regular fa-clock" aria-hidden="true"></i> <time datetime="19:00">7:00 p. m.</time><span>–</span><time datetime="21:30">9:30 p. m.</time></p>
                </div>
            </article>
            <article class="dia-horario dia-sin-clase">
                <h3>Jueves</h3>
                <p>Dia sin clase</p>
            </article>
            <article class="dia-horario dia-con-clase">
                <h3>Viernes</h3>
                <div class="sesion-horario">
                    <span class="tipo-sesion">CLASE GRUPAL</span>
                    <h4>Jiu-Jitsu</h4>
                    <p class="detalle-sesion">Entrenamiento en equipo</p>
                    <p class="hora-sesion"><i class="fa-regular fa-clock" aria-hidden="true"></i> <time datetime="19:00">7:00 p. m.</time><span>–</span><time datetime="21:30">9:30 p. m.</time></p>
                </div>
            </article>
            <article class="dia-horario dia-particular">
                <h3>Sábado</h3>
                <div class="sesion-horario">
                    <span class="tipo-sesion">CLASES ESPECIALES</span>
                    <h4>Particulares y especiales</h4>
                    <p class="detalle-sesion">Horario por acordar</p>
                </div>
            </article>
            <article class="dia-horario dia-particular">
                <h3>Domingo</h3>
                <div class="sesion-horario">
                    <span class="tipo-sesion">CLASES ESPECIALES</span>
                    <h4>Particulares y especiales</h4>
                    <p class="detalle-sesion">Horario por acordar</p>
                </div>
            </article>
        </div>
    </section>

    <p class="nota-horario"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Las clases particulares y especiales se realizan sábado y domingo; consulta el horario disponible.</p>

    <section class="solicitud-clase" aria-label="Solicitar una clase">
        <p>Inicia tus clases o solicita una clase particular.</p>
        <a class="boton boton-principal" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/login.php">
            Solicitar clase <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </a>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
