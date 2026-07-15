import { useEffect, useRef } from 'react';

function isDarkMode() {
    return document.documentElement.classList.contains('dark');
}

export default function DynamicAuthBackground() {
    const canvasRef = useRef(null);

    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const particles = [];
        let animationId;
        let dark = isDarkMode();

        const resizeCanvas = () => {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        };

        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);

        const themeObserver = new MutationObserver(() => {
            dark = isDarkMode();
        });
        themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });

        const createParticles = () => {
            const particleCount = 50;
            for (let i = 0; i < particleCount; i++) {
                particles.push({
                    x: Math.random() * canvas.width,
                    y: Math.random() * canvas.height,
                    size: Math.random() * 2 + 1,
                    speedX: Math.random() * 0.5 - 0.25,
                    speedY: Math.random() * 0.5 - 0.25,
                    opacity: Math.random() * 0.5 + 0.2,
                    hue: Math.random() * 60 + (dark ? 180 : 150),
                });
            }
        };

        createParticles();

        const drawBackground = () => {
            const gradient = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
            if (dark) {
                gradient.addColorStop(0, '#0c221d');
                gradient.addColorStop(0.45, '#0f172a');
                gradient.addColorStop(1, '#164e63');
            } else {
                gradient.addColorStop(0, '#d7efe6');
                gradient.addColorStop(0.45, '#e8eef8');
                gradient.addColorStop(1, '#cfe7f5');
            }
            ctx.fillStyle = gradient;
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            const time = Date.now() * 0.0003;
            const overlay = ctx.createLinearGradient(
                Math.sin(time) * canvas.width,
                Math.cos(time) * canvas.height,
                Math.cos(time) * canvas.width,
                Math.sin(time) * canvas.height,
            );
            if (dark) {
                overlay.addColorStop(0, 'rgba(47, 143, 115, 0.12)');
                overlay.addColorStop(0.5, 'rgba(14, 165, 233, 0.08)');
                overlay.addColorStop(1, 'rgba(36, 107, 159, 0.12)');
            } else {
                overlay.addColorStop(0, 'rgba(47, 143, 115, 0.18)');
                overlay.addColorStop(0.5, 'rgba(36, 107, 159, 0.10)');
                overlay.addColorStop(1, 'rgba(252, 209, 22, 0.12)');
            }
            ctx.fillStyle = overlay;
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        };

        const drawGrid = () => {
            const time = Date.now() * 0.0001;
            const gridSize = 60;
            const alpha = dark ? 0.08 + Math.sin(time) * 0.04 : 0.12 + Math.sin(time) * 0.04;
            ctx.strokeStyle = dark ? `rgba(127, 192, 170, ${alpha})` : `rgba(32, 93, 78, ${alpha})`;
            ctx.lineWidth = 0.5;

            for (let x = -gridSize; x < canvas.width + gridSize; x += gridSize) {
                ctx.beginPath();
                ctx.moveTo(x, 0);
                ctx.lineTo(x, canvas.height);
                ctx.stroke();
            }

            for (let y = -gridSize; y < canvas.height + gridSize; y += gridSize) {
                ctx.beginPath();
                ctx.moveTo(0, y);
                ctx.lineTo(canvas.width, y);
                ctx.stroke();
            }
        };

        const drawParticles = () => {
            particles.forEach((particle) => {
                particle.x += particle.speedX;
                particle.y += particle.speedY;

                if (particle.x < 0) particle.x = canvas.width;
                if (particle.x > canvas.width) particle.x = 0;
                if (particle.y < 0) particle.y = canvas.height;
                if (particle.y > canvas.height) particle.y = 0;

                const time = Date.now() * 0.001;
                const opacity = Math.max(0, particle.opacity + Math.sin(time * 0.001 + particle.x) * 0.3);
                const lightness = dark ? 62 : 38;

                ctx.fillStyle = `hsla(${particle.hue}, 55%, ${lightness}%, ${opacity})`;
                ctx.beginPath();
                ctx.arc(particle.x, particle.y, particle.size, 0, Math.PI * 2);
                ctx.fill();

                particles.forEach((otherParticle) => {
                    const dx = particle.x - otherParticle.x;
                    const dy = particle.y - otherParticle.y;
                    const distance = Math.sqrt(dx * dx + dy * dy);

                    if (distance < 150) {
                        const lineAlpha = (dark ? 0.14 : 0.1) * (1 - distance / 150);
                        ctx.strokeStyle = dark
                            ? `rgba(127, 192, 170, ${lineAlpha})`
                            : `rgba(32, 93, 78, ${lineAlpha})`;
                        ctx.lineWidth = 0.5;
                        ctx.beginPath();
                        ctx.moveTo(particle.x, particle.y);
                        ctx.lineTo(otherParticle.x, otherParticle.y);
                        ctx.stroke();
                    }
                });
            });
        };

        const drawOrbs = () => {
            const time = Date.now() * 0.0003;

            const x1 = canvas.width * 0.2 + Math.sin(time) * 100;
            const y1 = canvas.height * 0.3 + Math.cos(time * 0.7) * 100;
            const gradient1 = ctx.createRadialGradient(x1, y1, 0, x1, y1, 80);
            gradient1.addColorStop(0, dark ? 'rgba(47, 143, 115, 0.28)' : 'rgba(47, 143, 115, 0.22)');
            gradient1.addColorStop(1, 'rgba(47, 143, 115, 0)');
            ctx.fillStyle = gradient1;
            ctx.fillRect(x1 - 80, y1 - 80, 160, 160);

            const x2 = canvas.width * 0.8 + Math.sin(time * 0.9) * 120;
            const y2 = canvas.height * 0.7 + Math.cos(time * 0.6) * 120;
            const gradient2 = ctx.createRadialGradient(x2, y2, 0, x2, y2, 100);
            gradient2.addColorStop(0, dark ? 'rgba(36, 107, 159, 0.24)' : 'rgba(36, 107, 159, 0.18)');
            gradient2.addColorStop(1, 'rgba(36, 107, 159, 0)');
            ctx.fillStyle = gradient2;
            ctx.fillRect(x2 - 100, y2 - 100, 200, 200);
        };

        const animate = () => {
            drawBackground();
            drawGrid();
            drawOrbs();
            drawParticles();
            animationId = requestAnimationFrame(animate);
        };

        animate();

        return () => {
            window.removeEventListener('resize', resizeCanvas);
            themeObserver.disconnect();
            cancelAnimationFrame(animationId);
        };
    }, []);

    return (
        <canvas
            ref={canvasRef}
            className="fixed inset-0 -z-10"
            style={{ display: 'block' }}
        />
    );
}
