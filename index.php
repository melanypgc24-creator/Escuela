<?php
$paginaActual = 'inicio';
include __DIR__ . '/includes/header.php';
?>

<main class="contenido-inicio" id="inicio">
    <section class="portada" aria-labelledby="titulo-portada">
        <div class="contenido-portada">
            <p class="texto-superior"><span></span> Escuela de Jiu-Jitsu · Matamoros</p>
            <h1 id="titulo-portada">Disciplina para crecer.<br><span>Confianza para avanzar.</span></h1>
            <p class="descripcion-portada">
                En Team Cats aprendemos Jiu-Jitsu en un ambiente de respeto, constancia y compañerismo.
                Cada entrenamiento es una oportunidad para superarte y mejorar.
            </p>
            <div class="botones-portada">
                <a class="boton boton-principal" href="#clases">
                    Conoce nuestras clases <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
                <a class="boton boton-secundario" href="#sobre-nosotros">Más sobre Team Cats</a>
            </div>
            <p class="titulo-valores-portada">Nosotros fomentamos la:</p>
            <div class="valores-portada" aria-label="Valores de Team Cats">
                <div><span>01</span><p>Disciplina</p></div>
                <div><span>02</span><p>Confianza</p></div>
                <div><span>03</span><p>Compañerismo</p></div>
            </div>
        </div>

        <div class="ilustracion-portada" aria-label="Emblema de la escuela Team Cats">
            <div class="orbita orbita-exterior"></div>
            <div class="orbita orbita-interior"></div>
            <div class="destello-ilustracion destello-superior" aria-hidden="true">✦</div>
            <div class="destello-ilustracion destello-inferior" aria-hidden="true">✦</div>
            <div class="marco-imagen-presentacion">
                <img src="imagenes/Presentacion.png" alt="Maestros de Jiu-Jitsu">
            </div>
            <div class="etiqueta-ilustracion">
                <span>TEAM CATS</span>
                <span>JIU-JITSU · MATAMOROS</span>
            </div>
        </div>
    </section>

    <section class="seccion-introduccion" id="sobre-nosotros" aria-labelledby="titulo-introduccion">
        <div class="encabezado-seccion">
            <p class="texto-superior"><span></span> Más que un entrenamiento</p>
            <h2 id="titulo-introduccion">Un espacio para aprender y superarte</h2>
            <p>Creemos que las artes marciales fortalecen el cuerpo y también enseñan habilidades para la vida.</p>
        </div>
        <div class="tarjetas-valores" id="clases">
            <article class="tarjeta-valor">
                <span class="icono-tarjeta"><i class="fa-solid fa-handshake-angle" aria-hidden="true"></i></span>
                <h3>Aprende a tu ritmo</h3>
                <p>Da tus primeros pasos y construye nuevas habilidades con práctica y constancia.</p>
            </article>
            <article class="tarjeta-valor">
                <span class="icono-tarjeta icono-tarjeta-rosa"><i class="fa-solid fa-shield-heart" aria-hidden="true"></i></span>
                <h3>Crece con confianza</h3>
                <p>Fortalece tu seguridad, enfoque y respeto dentro y fuera del tatami.</p>
            </article>
            <article class="tarjeta-valor">
                <span class="icono-tarjeta icono-tarjeta-azul"><i class="fa-solid fa-people-group" aria-hidden="true"></i></span>
                <h3>Entrena en equipo</h3>
                <p>Forma parte de una comunidad que aprende, se apoya y avanza unida.</p>
            </article>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
