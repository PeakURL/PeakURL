import { test, expect, type Page } from "@playwright/test";
import { drawerScenarios, mockDrawerPages } from "../fixtures/drawer.fixture";

/** Record actual travel on animation frames, including the very first mount. */
async function recordDrawerMotion(
	page: Page,
	trigger: string,
	panel: string,
	phase: "enter" | "leave"
) {
	return page
		.locator(trigger)
		.first()
		.evaluate(
			(button, { panel, phase }) =>
				new Promise<
					Array<{
						progress: number;
						easing: string;
						duration: string;
					}>
				>((resolve, reject) => {
					if (!(button instanceof HTMLButtonElement))
						return reject(
							new Error("Drawer control must be a button")
						);
					const frames: Array<{
						progress: number;
						easing: string;
						duration: string;
					}> = [];
					const startedAt = performance.now();
					button.click();
					const sample = () => {
						const element =
							document.querySelector<HTMLElement>(panel);
						if (
							element &&
							(element.hasAttribute(`data-${phase}`) ||
								frames.length > 0)
						) {
							const rect = element.getBoundingClientRect();
							const finalX =
								element.dir === "rtl" ||
								document.documentElement.dir === "rtl"
									? 0
									: innerWidth - rect.width;
							frames.push({
								progress:
									1 - Math.abs(rect.x - finalX) / rect.width,
								duration:
									getComputedStyle(element)
										.transitionDuration,
								easing: getComputedStyle(element)
									.transitionTimingFunction,
							});
						}
						if (
							(frames.length > 0 &&
								!element?.hasAttribute(`data-${phase}`)) ||
							performance.now() - startedAt > 2000
						)
							return resolve(frames);
						requestAnimationFrame(sample);
					};
					requestAnimationFrame(sample);
				}),
			{ panel, phase }
		);
}

for (const direction of ["ltr", "rtl"] as const) {
	test(`every drawer visibly slides on first opening and closing in ${direction}`, async ({
		page,
	}) => {
		await page.setViewportSize({ width: 1440, height: 900 });
		await mockDrawerPages(page, direction);
		for (const drawer of drawerScenarios) {
			await test.step(drawer.name, async () => {
				await page.goto(drawer.path);
				await expect(
					page.locator(drawer.trigger).first()
				).toBeVisible();
				for (const phase of ["enter", "leave"] as const) {
					const trigger =
						phase === "enter"
							? drawer.trigger
							: `${drawer.panel} ${drawer.close}`;
					const frames = await recordDrawerMotion(
						page,
						trigger,
						drawer.panel,
						phase
					);
					expect(
						frames.length,
						`${drawer.name} ${phase} must animate`
					).toBeGreaterThan(3);
					expect(
						frames.some(
							(frame) =>
								frame.progress > 0.1 && frame.progress < 0.9
						),
						"must pass through an intermediate position"
					).toBe(true);
					const first = frames[0];
					const last = frames.at(-1);
					if (!first || !last)
						throw new Error("Drawer motion was not recorded");
					expect(first.easing).toBe(
						phase === "enter"
							? "cubic-bezier(0.4, 0, 1, 1)"
							: "cubic-bezier(0, 0, 0.2, 1)"
					);
					expect(first.duration).toBe(
						phase === "enter" ? "0.5s" : "0.4s"
					);
					if (phase === "enter") {
						expect(first.progress).toBeLessThan(0.25);
						expect(last.progress).toBeGreaterThan(0.9);
					} else {
						expect(first.progress).toBeGreaterThan(0.75);
						expect(last.progress).toBeLessThan(0.1);
					}
				}
				await expect(page.locator(drawer.panel)).toHaveCount(0);
			});
		}
	});
}
