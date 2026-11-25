
const section = document.getElementById("about");

// ---------- Parallax suave do mouse ----------
// alvo = onde o mouse está (-1 a 1) | atual = valor que vai "correndo" até o alvo
let alvoX = 0, alvoY = 0;
let atualX = 0, atualY = 0;
let rodando = false;

function animar() {
  // aproxima o valor atual do alvo (efeito suave)
  atualX += (alvoX - atualX) * 0.08;
  atualY += (alvoY - atualY) * 0.08;

  section.style.setProperty("--mouse-x", atualX.toFixed(4));
  section.style.setProperty("--mouse-y", atualY.toFixed(4));

  const chegou = Math.abs(alvoX - atualX) < 0.001 && Math.abs(alvoY - atualY) < 0.001;
  if (chegou) {
    rodando = false; // para o loop quando não há mais movimento
  } else {
    requestAnimationFrame(animar);
  }
}


function iniciar() {
  if (!rodando) {
    rodando = true;
    requestAnimationFrame(animar);
  }
}

section.addEventListener("mousemove", function (evento) {
  const caixa = section.getBoundingClientRect();
  // centro da seção = 0, bordas = -1 e 1
  alvoX = ((evento.clientX - caixa.left) / caixa.width) * 2 - 1;
  alvoY = ((evento.clientY - caixa.top) / caixa.height) * 2 - 1;
  iniciar();
});

section.addEventListener("mouseleave", function () {
  alvoX = 0;
  alvoY = 0;
  iniciar();
});

// ---------- Imagem de reserva se alguma falhar ----------
document.querySelectorAll("img[data-fallback]").forEach(function (img) {
  img.addEventListener("error", function () {
    if (img.src !== img.dataset.fallback) {
      img.src = img.dataset.fallback;
    }
  });
});
