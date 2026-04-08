// ==========================================
// 1. LIBRERÍA DE SENTIMIENTOS MEJORADA (CON NEGACIÓN E INTENSIFICADORES)
// ==========================================
class LocalSentiment {
    constructor() {
        this.dict = { /* ... tu diccionario de palabras no cambia ... */
            // ESPAÑOL
            "bueno": 3, "excelente": 5, "bien": 2, "genial": 4, "increible": 5, "feliz": 3, "amor": 3, "gracias": 2,
            "correcto": 2, "mejor": 3, "capaz": 2, "inteligente": 3, "experiencia": 2, "logro": 3, "ganar": 3,
            "productivo": 2, "orgullo": 3, "perfecto": 4, "si": 1, "claro": 1, "seguro": 2, "respeto": 2,
            "malo": -3, "terrible": -5, "peor": -4, "odio": -4, "triste": -3, "error": -2, "fallo": -3,
            "problema": -2, "dificil": -2, "lento": -1, "jamás": -2, "nunca": -2, "no": -1, "duda": -1,
            "miedo": -3, "nervioso": -2, "molesto": -3, "feo": -2, "fracaso": -4, "culpa": -3,
            // INGLÉS
            "good": 3, "great": 4, "excellent": 5, "amazing": 5, "happy": 3, "awesome": 4, "love": 3,
            "best": 4, "better": 3, "confident": 3, "success": 4, "win": 4, "smart": 3, "skilled": 3,
            "bad": -3, "terrible": -5, "awful": -4, "hate": -4, "worst": -5, "sad": -3, "stupid": -4,
            "fail": -4, "hard": -2, "problem": -2, "wrong": -3, "nervous": -2, "fear": -3
        };
        // Palabras clave que modifican a la siguiente palabra
        this.negations = ["no", "not", "nunca", "never", "tampoco", "neither", "jamás"];
        this.intensifiers = ["muy", "very", "mucho", "so", "realmente", "really", "bastante", "quite", "demasiado", "too"];
    }

    analyze(text) {
        let score = 0;
        const words = text.toLowerCase().replace(/[.,\/#!$%\^&\*;:{}=\-_`~()]/g,"").split(/\s+/);

        for (let i = 0; i < words.length; i++) {
            const word = words[i];
            let wordScore = this.dict[word] || 0;

            if (wordScore === 0) continue;

            // Revisar palabra ANTERIOR
            if (i > 0) {
                const prevWord = words[i - 1];
                // 1. Si es una negación, invertir el puntaje
                if (this.negations.includes(prevWord)) {
                    wordScore *= -1;
                }
                // 2. Si es un intensificador, amplificar el puntaje (positivo o negativo)
                if (this.intensifiers.includes(prevWord)) {
                    wordScore *= 1.5;
                }
            }
            score += wordScore;
        }
        return score;
    }
}