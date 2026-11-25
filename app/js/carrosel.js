let slideAtual = 0;

const slides = document.querySelectorAll(".slide");

function mostrarSlide(indice) {

    slides[slideAtual].classList.remove("ativo");

    slideAtual = indice;

    slides[slideAtual].classList.add("ativo");
}

function avancar() {

    let proximo = (slideAtual + 1) % slides.length;

    mostrarSlide(proximo);
}

function voltar() {

    let anterior = (slideAtual - 1 + slides.length) % slides.length;

    mostrarSlide(anterior);
}