<?php
$paginaActual = 'sobre-nosotros';
include __DIR__ . '/includes/header.php';
?>

<main class="pagina-sobre-nosotros">
    <section class="presentacion-equipo" aria-labelledby="titulo-sobre-nosotros">
        <p class="etiqueta-equipo"><i class="fa-solid fa-paw" aria-hidden="true"></i> Jiu-Jitsu · Heroica Matamoros</p>
        <h1 id="titulo-sobre-nosotros">Crece dentro y fuera del tatami</h1>
        <p>En Team Cats compartimos el Jiu-Jitsu en un ambiente de respeto, disciplina y compañerismo.</p>
        <a class="boton boton-principal" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/clases_publicas.php">
            Conoce nuestro horario <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </a>
    </section>

    <section class="historia-equipo" aria-labelledby="titulo-historia">
        <div class="texto-historia">
            <p class="etiqueta-seccion">Nuestra escuela</p>
            <h2 id="titulo-historia">Una comunidad que aprende unida</h2>
            <p>Team Cats es una academia de Jiu-Jitsu en Heroica Matamoros, Tamaulipas. Entrenamos para desarrollar habilidades en el tatami y valores que acompañan a cada alumno dentro y fuera de la escuela.</p>
            <p>La información pública de la academia presenta al profesor Julio César Puga como cinturón negro.</p>
        </div>
        <aside class="dato-equipo" aria-label="Academia y profesor">
            <span class="icono-equipo"><i class="fa-solid fa-user-ninja" aria-hidden="true"></i></span>
            <p>Academia Jiu-Jitsu</p>
            <h3>Team Cats</h3>
            <span class="separador-equipo" aria-hidden="true"></span>
            <p>Profesor</p>
            <h3>Julio César Puga</h3>
            <span class="grado-equipo">Cinturón negro</span>
        </aside>
    </section>

    <section class="seminarios-equipo" aria-labelledby="titulo-seminarios">
        <div class="encabezado-seminarios">
            <p class="etiqueta-seccion">Aprendizaje y comunidad</p>
            <h2 id="titulo-seminarios">Seminarios y actividades especiales</h2>
            <p>Un seminario complementa las clases regulares con una sesión enfocada en un tema concreto. Según la actividad, puede abordar técnicas de Jiu-Jitsu o defensa personal y dar espacio para aprender y practicar en grupo.</p>
        </div>

        <div class="detalles-seminarios" aria-label="Qué puedes encontrar en un seminario">
            <article class="detalle-seminario">
                <span><i class="fa-solid fa-bullseye" aria-hidden="true"></i></span>
                <h3>Un tema central</h3>
                <p>La sesión se concentra en una materia o aspecto específico, en lugar de seguir el formato de una clase regular.</p>
            </article>
            <article class="detalle-seminario">
                <span><i class="fa-solid fa-person-chalkboard" aria-hidden="true"></i></span>
                <h3>Aprendizaje práctico</h3>
                <p>Un espacio para conocer el tema, observar cómo se aplica y practicarlo con orientación.</p>
            </article>
            <article class="detalle-seminario">
                <span><i class="fa-solid fa-people-group" aria-hidden="true"></i></span>
                <h3>Encuentro con la comunidad</h3>
                <p>Algunas actividades especiales también permiten compartir las artes marciales con personas de la comunidad.</p>
            </article>
        </div>

        <article class="actividad-equipo">
            <div class="descripcion-actividad">
                <span class="icono-actividad"><i class="fa-solid fa-shield-heart" aria-hidden="true"></i></span>
                <p class="etiqueta-seccion">Actividad especial</p>
                <h3>Defensa personal para mujeres</h3>
                <p>Con motivo del Día Internacional de la Mujer, la academia organizó una clase de defensa personal para mujeres. Matamoros Sport publicó el video el 9 de marzo de 2026; es un ejemplo de las actividades especiales que se comparten con la comunidad.</p>
                <a class="enlace-facebook" href="https://www.facebook.com/share/v/1AAiRdWSrA/" target="_blank" rel="noopener noreferrer">
                    Ver publicación en Facebook <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                </a>
            </div>
            <div class="video-actividad">
                <div id="fb-root"></div>
                <div
                    class="fb-video"
                    data-href="https://www.facebook.com/61586362100823/videos/en-el-marco-del-d%C3%ADa-internacional-de-la-mujer-se-realiz%C3%B3-la-clase-de-defensa-per/1952487905351562/"
                    data-show-text="false"
                    data-allowfullscreen="true"
                ></div>
            </div>
        </article>
        <p class="enlace-proximos-eventos">
            Para conocer próximos seminarios y eventos, consulta las publicaciones de
            <a href="https://www.facebook.com/share/1Kofvu9cYN/" target="_blank" rel="noopener noreferrer">Team Cats en Facebook</a>.
        </p>
        <script async defer crossorigin="anonymous" src="https://connect.facebook.net/es_LA/sdk.js#xfbml=1&amp;version=v27.0"></script>
    </section>

    <section class="ubicacion-opiniones" aria-labelledby="titulo-ubicacion">
        <div class="encabezado-ubicacion">
            <p class="etiqueta-seccion">Encuéntranos</p>
            <h2 id="titulo-ubicacion">Visítanos en Matamoros</h2>
            <p>Consulta la ubicación en el mapa y lee las opiniones y comentarios directamente en Google Maps.</p>
        </div>
        <div class="panel-ubicacion">
            <div class="panel-opiniones" aria-label="Opiniones y comentarios de Google">
                <script src="https://elfsightcdn.com/platform.js" async></script>
                <div class="widget-resenas elfsight-app-3a5200ec-0334-4b06-932b-997d8e323698" data-elfsight-app-lazy></div>
                <a class="boton boton-principal" href="https://maps.app.goo.gl/99gsczjpLSx2dLqj7" target="_blank" rel="noopener noreferrer">
                    Abrir en Google Maps <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                </a>
            </div>
            <div class="mapa-ubicacion">
                <iframe
                    src="https://maps.google.com/maps?q=25.862635%2C-97.5257311&amp;z=16&amp;output=embed"
                    title="Ubicación de Jiu Jitsu Matamoros en Google Maps"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                    allowfullscreen
                ></iframe>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
