import { useEffect, useRef } from 'react';

export default function DynamicAuthBackground() {
    const canvasRef = useRef(null);

    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const particles = [];
        let animationId;

        // Set canvas size
        const resizeCanvas = () => {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        };

        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);

        // Create particles
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
                    color: `hsl(${Math.random() * 60 + 180}, 70%, 60%)`, // Blue-green range
                });
            }
        };

        createParticles();

        // Draw gradient background
        const drawBackground = () => {
            const gradient = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
            gradient.addColorStop(0, '#1e3a8a'); // blue-900
            gradient.addColorStop(0.5, '#1e1b4b'); // slate-900
            gradient.addColorStop(1, '#164e63'); // cyan-900
            ctx.fillStyle = gradient;
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            // Add animated gradient overlay
            const time = Date.now() * 0.0003;
            const overlay = ctx.createLinearGradient(
                Math.sin(time) * canvas.width,
                Math.cos(time) * canvas.height,
                Math.cos(time) * canvas.width,
                Math.sin(time) * canvas.height
            );
            overlay.addColorStop(0, 'rgba(51, 102, 255, 0.08)'); // blue
            overlay.addColorStop(0.5, 'rgba(14, 165, 233, 0.05)'); // sky-blue
            overlay.addColorStop(1, 'rgba(20, 184, 166, 0.08)'); // teal
            ctx.fillStyle = overlay;
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        };

        // Draw animated grid
        const drawGrid = () => {
            const time = Date.now() * 0.0001;
            const gridSize = 60;
            ctx.strokeStyle = `rgba(51, 102, 255, ${0.08 + Math.sin(time) * 0.04})`;
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

        // Draw particles
        const drawParticles = () => {
            particles.forEach((particle) => {
                // Update position
                particle.x += particle.speedX;
                particle.y += particle.speedY;

                // Wrap around screen
                if (particle.x < 0) particle.x = canvas.width;
                if (particle.x > canvas.width) particle.x = 0;
                if (particle.y < 0) particle.y = canvas.height;
                if (particle.y > canvas.height) particle.y = 0;

                // Animate opacity
                const time = Date.now() * 0.001;
                const opacity = particle.opacity + Math.sin(time * 0.001 + particle.x) * 0.3;

                // Draw particle
                ctx.fillStyle = particle.color.replace(')', `, ${Math.max(0, opacity)})`);
                ctx.beginPath();
                ctx.arc(particle.x, particle.y, particle.size, 0, Math.PI * 2);
                ctx.fill();

                // Draw connecting lines to nearby particles
                particles.forEach((otherParticle) => {
                    const dx = particle.x - otherParticle.x;
                    const dy = particle.y - otherParticle.y;
                    const distance = Math.sqrt(dx * dx + dy * dy);

                    if (distance < 150) {
                        ctx.strokeStyle = `rgba(51, 102, 255, ${0.12 * (1 - distance / 150)})`;
                        ctx.lineWidth = 0.5;
                        ctx.beginPath();
                        ctx.moveTo(particle.x, particle.y);
                        ctx.lineTo(otherParticle.x, otherParticle.y);
                        ctx.stroke();
                    }
                });
            });
        };

        // Draw rotating orbs
        const drawOrbs = () => {
            const time = Date.now() * 0.0003;

            // Orb 1
            const x1 = canvas.width * 0.2 + Math.sin(time) * 100;
            const y1 = canvas.height * 0.3 + Math.cos(time * 0.7) * 100;
            const gradient1 = ctx.createRadialGradient(x1, y1, 0, x1, y1, 80);
            gradient1.addColorStop(0, 'rgba(59, 130, 246, 0.3)');
            gradient1.addColorStop(1, 'rgba(59, 130, 246, 0)');
            ctx.fillStyle = gradient1;
            ctx.fillRect(x1 - 80, y1 - 80, 160, 160);

            // Orb 2
            const x2 = canvas.width * 0.8 + Math.sin(time * 0.9) * 120;
            const y2 = canvas.height * 0.7 + Math.cos(time * 0.6) * 120;
            const gradient2 = ctx.createRadialGradient(x2, y2, 0, x2, y2, 100);
            gradient2.addColorStop(0, 'rgba(20, 184, 166, 0.2)');
            gradient2.addColorStop(1, 'rgba(20, 184, 166, 0)');
            ctx.fillStyle = gradient2;
            ctx.fillRect(x2 - 100, y2 - 100, 200, 200);
        };

        // Animation loop
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
